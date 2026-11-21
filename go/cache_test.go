package payrudder

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"net/http"
	"strings"
	"sync"
	"sync/atomic"
	"testing"
	"time"
)

// fakeAPI answers the reads Cache makes and counts each path.
type fakeAPI struct {
	mu    sync.Mutex
	calls []string
	used  atomic.Value
	delay time.Duration
}

func (f *fakeAPI) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	f.mu.Lock()
	f.calls = append(f.calls, r.Method+" "+r.URL.RequestURI())
	f.mu.Unlock()
	time.Sleep(f.delay)
	used, _ := f.used.Load().(string)
	if used == "" {
		used = "8"
	}
	ent := fmt.Sprintf(`{"customer_external_id":"cus","meter_code":"api","used":%q,"limit":"10","unlimited":false,"allowed":true,"mode":"hard"}`, used)
	w.Header().Set("Content-Type", "application/json")
	switch r.URL.Path {
	case "/v1/customers/cus/entitlements/api":
		_, _ = w.Write([]byte(ent))
	case "/v1/customers/cus/entitlements":
		_, _ = w.Write([]byte(`{"items":[` + ent + `]}`))
	case "/v1/plans":
		_, _ = w.Write([]byte(`{"plans":[{"id":"p1","code":"pro","name":"Pro","latest_version":2},{"id":"p2","code":"draft","name":"Draft","latest_version":0}]}`))
	case "/v1/plans/pro/versions/2":
		_, _ = w.Write([]byte(`{"plan_code":"pro","version":2,"prices":[]}`))
	case "/v1/credit-packs":
		_, _ = w.Write([]byte(`{"packs":[]}`))
	case "/v1/campaigns":
		_, _ = w.Write([]byte(`{"campaigns":[]}`))
	case "/v1/events":
		_, _ = w.Write([]byte(`{"results":[{"status":"accepted"}]}`))
	default:
		http.NotFound(w, r)
	}
}

func (f *fakeAPI) count(prefix string) int {
	f.mu.Lock()
	defer f.mu.Unlock()
	n := 0
	for _, c := range f.calls {
		if strings.HasPrefix(c, prefix) {
			n++
		}
	}
	return n
}

func newCache(t *testing.T, opts CacheOptions, clientOpts ...Option) (*Cache, *fakeAPI) {
	t.Helper()
	api := &fakeAPI{}
	c, _ := newTestClient(t, api, clientOpts...)
	return NewCache(c, opts), api
}

func usage(q string) IncomingEvent {
	return IncomingEvent{IdempotencyKey: "k", CustomerExternalID: "cus", MeterCode: "api", Quantity: Decimal(q), EventTime: time.Now()}
}

func TestCacheHitsAndCatalog(t *testing.T) {
	ctx := context.Background()
	cache, api := newCache(t, CacheOptions{})
	for range 2 {
		s, err := cache.Entitlement(ctx, "cus", "api")
		if err != nil || s.Used != "8" {
			t.Fatal(s, err)
		}
		if _, err := cache.Entitlements(ctx, "cus"); err != nil {
			t.Fatal(err)
		}
		cat, err := cache.Catalog(ctx)
		if err != nil || len(cat.Plans) != 1 || cat.Plans[0].Version.PlanCode != "pro" {
			t.Fatal(cat, err)
		}
	}
	for _, want := range []string{"GET /v1/customers/cus/entitlements/api", "GET /v1/customers/cus/entitlements", "GET /v1/plans", "GET /v1/credit-packs?active=true", "GET /v1/campaigns?status=running", "GET /v1/plans/pro/versions/2"} {
		if api.count(want) == 0 {
			t.Errorf("no %s in %v", want, api.calls)
		}
	}
	if len(api.calls) != 6 {
		t.Fatalf("calls %v", api.calls)
	}
}

func TestCacheSharesConcurrentMisses(t *testing.T) {
	cache, api := newCache(t, CacheOptions{})
	api.delay = 20 * time.Millisecond
	var wg sync.WaitGroup
	for range 5 {
		wg.Add(1)
		go func() {
			defer wg.Done()
			if _, err := cache.Entitlement(context.Background(), "cus", "api"); err != nil {
				t.Error(err)
			}
		}()
	}
	wg.Wait()
	if n := api.count("GET"); n != 1 {
		t.Fatalf("%d requests: %v", n, api.calls)
	}
}

func TestCacheWebhookInvalidation(t *testing.T) {
	ctx := context.Background()
	cache, api := newCache(t, CacheOptions{})
	_, _ = cache.Entitlement(ctx, "cus", "api")
	_, _ = cache.Catalog(ctx)
	api.used.Store("9")
	ev := func(typ, data string) *WebhookEvent { return &WebhookEvent{Type: typ, Data: json.RawMessage(data)} }
	if !cache.HandleWebhook(ctx, ev("subscription.changed", `{"subscription":{"customer_external_id":"cus"}}`)) {
		t.Fatal("subscription.changed did nothing")
	}
	if s, _ := cache.Entitlement(ctx, "cus", "api"); s.Used != "9" {
		t.Fatal("still cached", s.Used)
	}
	if !cache.HandleWebhook(ctx, ev(EventEntitlementUpdated, `{"customer_external_id":"cus","meter_code":""}`)) {
		t.Fatal("entitlement.updated did nothing")
	}
	if !cache.HandleWebhook(ctx, ev(EventInvoicePaid, `{"customer_external_id":"cus"}`)) || cache.HandleWebhook(ctx, ev(EventCustomerBlocked, `{}`)) {
		t.Fatal("invoice.paid did nothing, or acted on an event without a customer")
	}
	_, _ = cache.Catalog(ctx)
	if n := api.count("GET /v1/plans"); n != 2 {
		t.Fatal("catalog refetched before invalidation", n)
	}
	if !cache.HandleWebhook(ctx, ev(EventCampaignEnded, `{}`)) {
		t.Fatal("campaign.ended did nothing")
	}
	_, _ = cache.Catalog(ctx)
	if n := api.count("GET /v1/plans"); n != 4 {
		t.Fatal("catalog not refetched", n)
	}
}

func TestCacheTestAndLiveApart(t *testing.T) {
	ctx := context.Background()
	store := NewMemoryStore(0)
	test, testAPI := newCache(t, CacheOptions{Store: store})
	live, liveAPI := newCache(t, CacheOptions{Store: store}, WithAPIKey("prk_live_abcdefgh_secret"))
	liveAPI.used.Store("1")
	if s, _ := test.Entitlement(ctx, "cus", "api"); s.Used != "8" {
		t.Fatal(s.Used)
	}
	if s, _ := live.Entitlement(ctx, "cus", "api"); s.Used != "1" {
		t.Fatal(s.Used)
	}
	test.InvalidateCustomer(ctx, "cus")
	_, _ = live.Entitlement(ctx, "cus", "api")
	if testAPI.count("GET") != 1 || liveAPI.count("GET") != 1 {
		t.Fatal(testAPI.calls, liveAPI.calls)
	}
}

func TestCacheRecordUsageCrossesHardLimit(t *testing.T) {
	ctx := context.Background()
	cache, api := newCache(t, CacheOptions{})
	_, _ = cache.Entitlement(ctx, "cus", "api")
	_, _ = cache.Entitlements(ctx, "cus")
	if _, err := cache.RecordUsage(ctx, usage("1.5")); err != nil {
		t.Fatal(err)
	}
	s, _ := cache.Entitlement(ctx, "cus", "api")
	if s.Used != "9.5" || !s.Allowed {
		t.Fatal(s.Used, s.Allowed)
	}
	_, _ = cache.RecordUsage(ctx, usage("0.5"))
	s, _ = cache.Entitlement(ctx, "cus", "api")
	all, _ := cache.Entitlements(ctx, "cus")
	if s.Used != "10" || s.Allowed || all[0].Used != "10" || all[0].Allowed {
		t.Fatal(s.Used, s.Allowed, all[0].Used, all[0].Allowed)
	}
	if n := api.count("GET"); n != 2 {
		t.Fatal(api.calls)
	}
}

func TestCacheDropsAnEntryItCannotUpdate(t *testing.T) {
	ctx := context.Background()
	store := NewMemoryStore(0)
	cache, api := newCache(t, CacheOptions{Store: store})
	_, _ = cache.Entitlement(ctx, "cus", "api")
	gen, _, _ := store.Get(ctx, "payrudder:test:gen:c:cus")
	_ = store.Set(ctx, "payrudder:test:ent:cus:"+gen+":api", `{"used":"lots"}`, time.Minute)
	_, _ = cache.RecordUsage(ctx, usage("1"))
	_, _ = cache.Entitlement(ctx, "cus", "api")
	if n := api.count("GET /v1/customers/cus/entitlements/api"); n != 2 {
		t.Fatal(n)
	}
}

type brokenStore struct{}

func (brokenStore) Get(context.Context, string) (string, bool, error) {
	return "", false, errors.New("down")
}
func (brokenStore) Set(context.Context, string, string, time.Duration) error {
	return errors.New("down")
}
func (brokenStore) Delete(context.Context, string) error { return errors.New("down") }

func TestCacheStoreFailureReadsThrough(t *testing.T) {
	ctx := context.Background()
	var errs atomic.Int32
	cache, api := newCache(t, CacheOptions{Store: brokenStore{}, OnError: func(error) { errs.Add(1) }})
	s, err := cache.Entitlement(ctx, "cus", "api")
	if err != nil || s.Used != "8" {
		t.Fatal(s, err)
	}
	if _, err := cache.RecordUsage(ctx, usage("1")); err != nil {
		t.Fatal(err)
	}
	cache.InvalidateCatalog(ctx)
	if api.count("GET") != 1 || errs.Load() < 3 {
		t.Fatal(api.calls, errs.Load())
	}
}

func TestMemoryStoreExpiresAndEvicts(t *testing.T) {
	ctx := context.Background()
	m := NewMemoryStore(2)
	now := time.Unix(0, 0)
	m.now = func() time.Time { return now }
	_ = m.Set(ctx, "a", "1", time.Second)
	_ = m.Set(ctx, "b", "2", time.Minute)
	_, _, _ = m.Get(ctx, "a")
	_ = m.Set(ctx, "c", "3", time.Minute)
	if _, ok, _ := m.Get(ctx, "b"); ok {
		t.Fatal("b should be evicted as least recently used")
	}
	now = now.Add(2 * time.Second)
	if _, ok, _ := m.Get(ctx, "a"); ok {
		t.Fatal("a should be expired")
	}
	if v, ok, _ := m.Get(ctx, "c"); !ok || v != "3" {
		t.Fatal(v, ok)
	}
}
