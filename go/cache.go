package payrudder

import (
	"container/list"
	"context"
	"crypto/rand"
	"encoding/hex"
	"encoding/json"
	"math/big"
	"slices"
	"strconv"
	"strings"
	"sync"
	"time"
)

// CacheStore is where a Cache keeps its entries. NewMemoryStore ships with the SDK; a go-redis adapter is a few lines
// (see the README), so this module needs no Redis dependency.
type CacheStore interface {
	// Get returns ok false for a missing or expired key.
	Get(ctx context.Context, key string) (value string, ok bool, err error)
	Set(ctx context.Context, key, value string, ttl time.Duration) error
	Delete(ctx context.Context, key string) error
}

// MemoryStore is a least-recently-used CacheStore in the memory of this process. It is safe for concurrent use.
type MemoryStore struct {
	mu    sync.Mutex
	max   int
	order *list.List // front: most recently used
	items map[string]*list.Element
	now   func() time.Time
}

type memoryEntry struct {
	key, value string
	until      time.Time
}

// NewMemoryStore keeps at most maxEntries entries (10000 when maxEntries <= 0).
func NewMemoryStore(maxEntries int) *MemoryStore {
	if maxEntries <= 0 {
		maxEntries = 10000
	}
	return &MemoryStore{max: maxEntries, order: list.New(), items: map[string]*list.Element{}, now: time.Now}
}

// Get implements CacheStore.
func (m *MemoryStore) Get(_ context.Context, key string) (string, bool, error) {
	m.mu.Lock()
	defer m.mu.Unlock()
	el, ok := m.items[key]
	if !ok {
		return "", false, nil
	}
	e := el.Value.(*memoryEntry)
	if !m.now().Before(e.until) {
		m.order.Remove(el)
		delete(m.items, key)
		return "", false, nil
	}
	m.order.MoveToFront(el)
	return e.value, true, nil
}

// Set implements CacheStore.
func (m *MemoryStore) Set(_ context.Context, key, value string, ttl time.Duration) error {
	m.mu.Lock()
	defer m.mu.Unlock()
	e := &memoryEntry{key: key, value: value, until: m.now().Add(ttl)}
	if el, ok := m.items[key]; ok {
		el.Value = e
		m.order.MoveToFront(el)
		return nil
	}
	m.items[key] = m.order.PushFront(e)
	if m.order.Len() > m.max {
		oldest := m.order.Back()
		m.order.Remove(oldest)
		delete(m.items, oldest.Value.(*memoryEntry).key)
	}
	return nil
}

// Delete implements CacheStore.
func (m *MemoryStore) Delete(_ context.Context, key string) error {
	m.mu.Lock()
	defer m.mu.Unlock()
	if el, ok := m.items[key]; ok {
		m.order.Remove(el)
		delete(m.items, key)
	}
	return nil
}

// CacheOptions configures NewCache. Zero values take the defaults.
type CacheOptions struct {
	// Store defaults to NewMemoryStore(0).
	Store CacheStore
	// Prefix defaults to "payrudder:". The API key's environment follows it, so test and live never share entries.
	Prefix string
	// EntitlementTTL defaults to 60 s, CatalogTTL to 10 min: how long an answer is served without asking again.
	EntitlementTTL time.Duration
	CatalogTTL     time.Duration
	// AccessTTL defaults to 5 s: short, so a cached Access answer stays close to live.
	AccessTTL time.Duration
	// OnError is called with every store error; the call itself reads through to the API instead of failing.
	OnError func(error)
}

// CatalogPlan is a plan with its current (latest published) version and prices.
type CatalogPlan struct {
	Plan    Plan        `json:"plan"`
	Version PlanVersion `json:"version"`
}

// Catalog is what a pricing page needs: plans with their current prices, active credit packs and running campaigns.
type Catalog struct {
	Plans     []CatalogPlan `json:"plans"`
	Packs     []CreditPack  `json:"packs"`
	Campaigns []Campaign    `json:"campaigns"`
}

// Cache answers entitlement checks and the catalog from a store every instance of your app shares. Payrudder stays the
// source of truth: pass verified webhooks to HandleWebhook and a change invalidates the cached answers at once; without
// it they are refreshed after the TTL.
//
// Invalidation is by generation: each customer and the catalog have a generation key, entries are stored under the
// generation they were read at, and invalidating writes a new random generation, so the store needs only Get, Set
// with a TTL and Delete. A Cache is safe for concurrent use, and concurrent misses of one key share one request.
type Cache struct {
	client                 *Client
	store                  CacheStore
	base                   string
	entTTL, catTTL, genTTL time.Duration
	accTTL                 time.Duration
	onError                func(error)
	mu                     sync.Mutex
	inflight               map[string]*inflightCall
}

type inflightCall struct {
	done chan struct{}
	val  []byte
	err  error
}

// NewCache returns a Cache over the client.
func NewCache(client *Client, opts CacheOptions) *Cache {
	c := &Cache{client: client, store: opts.Store, entTTL: opts.EntitlementTTL, catTTL: opts.CatalogTTL, accTTL: opts.AccessTTL, onError: opts.OnError, inflight: map[string]*inflightCall{}}
	if c.store == nil {
		c.store = NewMemoryStore(0)
	}
	prefix := opts.Prefix
	if prefix == "" {
		prefix = "payrudder:"
	}
	c.base = prefix + client.Environment() + ":"
	if c.entTTL <= 0 {
		c.entTTL = time.Minute
	}
	if c.catTTL <= 0 {
		c.catTTL = 10 * time.Minute
	}
	if c.accTTL <= 0 {
		c.accTTL = 5 * time.Second
	}
	c.genTTL = 2 * max(c.entTTL, c.catTTL, c.accTTL)
	if c.onError == nil {
		c.onError = func(error) {}
	}
	return c
}

// Entitlement is the customer's status on one meter.
func (c *Cache) Entitlement(ctx context.Context, customer, meter string) (*EntitlementStatus, error) {
	s, err := cached(ctx, c, c.customerGenKey(customer), func(g string) string { return "ent:" + customer + ":" + g + ":" + meter }, c.entTTL,
		func(ctx context.Context) (*EntitlementStatus, error) {
			return c.client.Customers.GetEntitlement(ctx, customer, meter, nil)
		})
	return s, err
}

// Entitlements is the customer's status on every meter they have an entitlement on.
func (c *Cache) Entitlements(ctx context.Context, customer string) ([]EntitlementStatus, error) {
	return cached(ctx, c, c.customerGenKey(customer), func(g string) string { return "ents:" + customer + ":" + g }, c.entTTL,
		func(ctx context.Context) ([]EntitlementStatus, error) {
			res, err := c.client.Customers.ListEntitlements(ctx, customer, nil)
			if err != nil {
				return nil, err
			}
			return res.Items, nil
		})
}

// Access says whether the customer may use the service now: subscription, payment and the meters given (their order
// does not matter). member, when not empty, is also checked to be a member of the customer.
func (c *Cache) Access(ctx context.Context, customer string, meters []string, member string) (*CustomerAccess, error) {
	sorted := slices.Compact(slices.Sorted(slices.Values(meters)))
	keyOf := func(g string) string {
		return "acc:" + customer + ":" + g + ":" + strings.Join(sorted, ",") + ":" + member
	}
	a, err := cached(ctx, c, c.customerGenKey(customer), keyOf, c.accTTL, func(ctx context.Context) (*CustomerAccess, error) {
		return c.client.Customers.GetCustomerAccess(ctx, customer, &GetCustomerAccessParams{Meters: strings.Join(sorted, ","), Member: member})
	})
	if err == nil {
		if gen, ok := c.get(ctx, c.customerGenKey(customer)); ok && gen != "" {
			c.remember(ctx, customer, gen, keyOf(gen))
		}
	}
	return a, err
}

// remember lists the access answers cached under a generation in one key, so RecordUsage can find those that hold a
// meter. shortcut: the last 50 keys only; a customer checked with more meter sets loses counting on the oldest until
// their TTL ends.
func (c *Cache) remember(ctx context.Context, customer, gen, key string) {
	index := c.base + "acc-keys:" + customer + ":" + gen
	var keys []string
	if raw, ok := c.get(ctx, index); ok && raw != "" {
		_ = json.Unmarshal([]byte(raw), &keys)
	}
	if slices.Contains(keys, key) {
		return
	}
	keys = append(keys, key)
	if len(keys) > 50 {
		keys = keys[len(keys)-50:]
	}
	c.setJSON(ctx, index, keys, c.accTTL)
}

func (c *Cache) countIntoAccess(ctx context.Context, customer, gen, meter, quantity string) {
	raw, ok := c.get(ctx, c.base+"acc-keys:"+customer+":"+gen)
	if !ok || raw == "" {
		return
	}
	var keys []string
	if json.Unmarshal([]byte(raw), &keys) != nil {
		return
	}
	for _, k := range keys {
		key := c.base + k
		hit, ok := c.get(ctx, key)
		if !ok || hit == "" {
			continue
		}
		var a CustomerAccess
		if json.Unmarshal([]byte(hit), &a) != nil {
			c.del(ctx, key)
			continue
		}
		i := slices.IndexFunc(a.Meters, func(m EntitlementStatus) bool { return m.MeterCode == meter })
		if i < 0 {
			continue
		}
		if !countUsage(&a.Meters[i], quantity) {
			c.del(ctx, key)
			continue
		}
		if s := a.Meters[i]; !s.Allowed && s.Mode == "hard" && !s.Suspended {
			if a.Allowed {
				a.Allowed, a.Reason = false, "limit_reached"
			}
			// The notice the API would now give for this meter, in place of a limit_near one.
			a.Notices = slices.DeleteFunc(a.Notices, func(n AccessNotice) bool { return n.MeterCode == meter })
			a.Notices = append(a.Notices, AccessNotice{Code: "limit_reached", Severity: "critical", MeterCode: meter})
		}
		c.setJSON(ctx, key, a, c.accTTL)
	}
}

// Catalog is the plans with their current prices, the active credit packs and the running campaigns.
func (c *Cache) Catalog(ctx context.Context) (*Catalog, error) {
	return cached(ctx, c, c.base+"gen:catalog", func(g string) string { return "catalog:" + g }, c.catTTL,
		func(ctx context.Context) (*Catalog, error) {
			plans, err := c.client.Plans.ListPlans(ctx)
			if err != nil {
				return nil, err
			}
			active := true
			packs, err := c.client.CreditPacks.ListCreditPacks(ctx, &ListCreditPacksParams{Active: &active})
			if err != nil {
				return nil, err
			}
			campaigns, err := c.client.Campaigns.ListCampaigns(ctx, &ListCampaignsParams{Status: "running"})
			if err != nil {
				return nil, err
			}
			out := &Catalog{Plans: []CatalogPlan{}, Packs: packs.Packs, Campaigns: campaigns.Campaigns}
			for _, p := range plans.Plans {
				if p.LatestVersion < 1 {
					continue // nothing published: no prices to show
				}
				v, err := c.client.Plans.GetPlanVersion(ctx, p.Code, strconv.Itoa(p.LatestVersion))
				if err != nil {
					return nil, err
				}
				out.Plans = append(out.Plans, CatalogPlan{Plan: p, Version: *v})
			}
			return out, nil
		})
}

// RecordUsage records one usage event through the API, then counts it into the cached entitlement of that customer and
// meter (Used += Quantity, Allowed recomputed for a hard limit), so the next check needs no request.
func (c *Cache) RecordUsage(ctx context.Context, ev IncomingEvent) (EventResult, error) {
	results, err := c.client.Events.Ingest(ctx, []IncomingEvent{ev})
	if err != nil || len(results) == 0 {
		return EventResult{}, err
	}
	if results[0].Status != "accepted" {
		return results[0], nil
	}
	gen, ok := c.get(ctx, c.customerGenKey(ev.CustomerExternalID))
	if !ok || gen == "" {
		return results[0], nil // no generation: nothing was cached under it
	}
	q := string(ev.Quantity)
	one := c.base + "ent:" + ev.CustomerExternalID + ":" + gen + ":" + ev.MeterCode
	if raw, ok := c.get(ctx, one); ok && raw != "" {
		var s EntitlementStatus
		if json.Unmarshal([]byte(raw), &s) == nil && countUsage(&s, q) {
			c.setJSON(ctx, one, s, c.entTTL)
		} else {
			c.del(ctx, one)
		}
	}
	c.countIntoAccess(ctx, ev.CustomerExternalID, gen, ev.MeterCode, q)
	all := c.base + "ents:" + ev.CustomerExternalID + ":" + gen
	if raw, ok := c.get(ctx, all); ok && raw != "" {
		var items []EntitlementStatus
		if json.Unmarshal([]byte(raw), &items) != nil {
			c.del(ctx, all)
			return results[0], nil
		}
		for i := range items {
			if items[i].MeterCode != ev.MeterCode {
				continue
			}
			if countUsage(&items[i], q) {
				c.setJSON(ctx, all, items, c.entTTL)
			} else {
				c.del(ctx, all)
			}
			break
		}
	}
	return results[0], nil
}

// HandleWebhook invalidates by the event's type (already verified, see ConstructEvent) and reports whether it did
// anything: the customer on entitlement.updated, customer.blocked, customer.unblocked, credits.purchased, invoice.paid,
// invoice.uncollectible, invoice.voided and every
// subscription.* event; the catalog on plan.created, plan.version_published, campaign.started and campaign.ended.
func (c *Cache) HandleWebhook(ctx context.Context, ev *WebhookEvent) bool {
	switch ev.Type {
	case "plan.created", "plan.version_published", EventCampaignStarted, EventCampaignEnded:
		c.InvalidateCatalog(ctx)
		return true
	case EventEntitlementUpdated, EventCustomerBlocked, EventCustomerUnblocked, EventCreditsPurchased, EventInvoicePaid, EventInvoiceUncollectible, EventInvoiceVoided:
	default:
		if !strings.HasPrefix(ev.Type, "subscription.") {
			return false
		}
	}
	var data struct {
		CustomerExternalID string `json:"customer_external_id"`
		Subscription       struct {
			CustomerExternalID string `json:"customer_external_id"`
		} `json:"subscription"`
	}
	if len(ev.Data) > 0 && json.Unmarshal(ev.Data, &data) != nil {
		return false
	}
	customer := data.CustomerExternalID
	if customer == "" {
		customer = data.Subscription.CustomerExternalID
	}
	if customer == "" {
		return false
	}
	c.InvalidateCustomer(ctx, customer)
	return true
}

// InvalidateCustomer makes the customer's next entitlement checks ask the API.
func (c *Cache) InvalidateCustomer(ctx context.Context, customer string) {
	c.set(ctx, c.customerGenKey(customer), token(), c.genTTL)
}

// InvalidateCatalog makes the next catalog read ask the API.
func (c *Cache) InvalidateCatalog(ctx context.Context) {
	c.set(ctx, c.base+"gen:catalog", token(), c.genTTL)
}

func (c *Cache) customerGenKey(customer string) string { return c.base + "gen:c:" + customer }

// generation returns the generation stored at key, creating a missing one; "" when the store failed (nothing is then
// cached). It is not refreshed on read: re-setting the value read could undo an invalidation written in between, and
// a generation that expires costs one miss (the new one is random).
func (c *Cache) generation(ctx context.Context, key string) string {
	v, _ := c.shared(key, func() ([]byte, error) {
		g, ok := c.get(ctx, key)
		if !ok {
			return nil, nil
		}
		if g != "" {
			return []byte(g), nil
		}
		fresh := token()
		if !c.set(ctx, key, fresh, c.genTTL) {
			return nil, nil
		}
		return []byte(fresh), nil
	})
	return string(v)
}

func cached[T any](ctx context.Context, c *Cache, genKey string, keyOf func(gen string) string, ttl time.Duration, load func(context.Context) (T, error)) (T, error) {
	gen := c.generation(ctx, genKey)
	if gen == "" {
		return load(ctx)
	}
	key := c.base + keyOf(gen)
	if raw, ok := c.get(ctx, key); ok && raw != "" {
		var hit T
		if json.Unmarshal([]byte(raw), &hit) == nil {
			return hit, nil
		}
	}
	b, err := c.shared(key, func() ([]byte, error) {
		v, err := load(ctx)
		if err != nil {
			return nil, err
		}
		b, err := json.Marshal(v)
		if err != nil {
			return nil, err
		}
		c.set(ctx, key, string(b), ttl)
		return b, nil
	})
	var out T
	if err != nil {
		return out, err
	}
	err = json.Unmarshal(b, &out)
	return out, err
}

// shared runs fn once for concurrent callers with the same key.
func (c *Cache) shared(key string, fn func() ([]byte, error)) ([]byte, error) {
	c.mu.Lock()
	if call, ok := c.inflight[key]; ok {
		c.mu.Unlock()
		<-call.done
		return call.val, call.err
	}
	call := &inflightCall{done: make(chan struct{})}
	c.inflight[key] = call
	c.mu.Unlock()
	call.val, call.err = fn()
	c.mu.Lock()
	delete(c.inflight, key)
	c.mu.Unlock()
	close(call.done)
	return call.val, call.err
}

// get returns ok false when the store failed; a missing key is ("", true).
func (c *Cache) get(ctx context.Context, key string) (string, bool) {
	v, found, err := c.store.Get(ctx, key)
	if err != nil {
		c.onError(err)
		return "", false
	}
	if !found {
		return "", true
	}
	return v, true
}

func (c *Cache) set(ctx context.Context, key, value string, ttl time.Duration) bool {
	if err := c.store.Set(ctx, key, value, ttl); err != nil {
		c.onError(err)
		return false
	}
	return true
}

func (c *Cache) setJSON(ctx context.Context, key string, v any, ttl time.Duration) {
	b, err := json.Marshal(v)
	if err != nil {
		c.del(ctx, key)
		return
	}
	c.set(ctx, key, string(b), ttl)
}

func (c *Cache) del(ctx context.Context, key string) {
	if err := c.store.Delete(ctx, key); err != nil {
		c.onError(err)
	}
}

func token() string {
	b := make([]byte, 8)
	_, _ = rand.Read(b)
	return hex.EncodeToString(b)
}

// countUsage adds quantity to s.Used and recomputes OverLimit, and Allowed for a hard limit. False: s lacks what is
// needed (the caller then drops the entry).
func countUsage(s *EntitlementStatus, quantity string) bool {
	used, ok1 := new(big.Rat).SetString(s.Used)
	q, ok2 := new(big.Rat).SetString(quantity)
	if !ok1 || !ok2 {
		return false
	}
	used.Add(used, q)
	s.Used = formatDecimal(used, max(fracDigits(s.Used), fracDigits(quantity)))
	if s.Unlimited || s.Limit == nil {
		return true
	}
	limit, ok := new(big.Rat).SetString(*s.Limit)
	if !ok {
		return false
	}
	cmp := used.Cmp(limit)
	s.OverLimit = cmp > 0
	if s.Mode == "hard" {
		s.Allowed = !s.Suspended && cmp < 0
	}
	return true
}

func fracDigits(s string) int {
	if i := strings.IndexByte(s, '.'); i >= 0 {
		return len(s) - i - 1
	}
	return 0
}

func formatDecimal(r *big.Rat, digits int) string {
	s := r.FloatString(digits)
	if strings.Contains(s, ".") {
		s = strings.TrimRight(strings.TrimRight(s, "0"), ".")
	}
	return s
}
