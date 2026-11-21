package payrudder

import (
	"context"
	"strings"
)

// ConnectParams says who Connect is about: your own user (or organisation) id, and for an organisation the signed-in
// person as Member.
type ConnectParams struct {
	ID, Name string
	// Email is required for a person; a new organisation may leave it out and takes its first member's.
	Email, Locale string
	// Plan is the plan the customer page opens with when the customer has no running subscription.
	Plan   string
	Member *ConnectMember
	// ExpiresInDays defaults to 1.
	ExpiresInDays int
}

// ConnectMember is the signed-in person of an organisation.
type ConnectMember = ConnectCustomerMember

// WithCache gives the client a Cache: Access, RecordUsage and HandleWebhook then go through it.
func WithCache(opts CacheOptions) Option {
	return func(c *Client) { c.cacheOpts = &opts }
}

// Cache is the client's cache, nil without WithCache.
func (c *Client) Cache() *Cache { return c.cache }

// Connect creates or updates the customer and returns the link to their customer page (URL is nil for a member who
// does not manage payment).
func (c *Client) Connect(ctx context.Context, p ConnectParams, opts ...RequestOption) (*ConnectCustomerResponse, error) {
	req := &ConnectCustomerRequest{Name: p.Name, Email: p.Email, Locale: p.Locale, PlanCode: p.Plan, Member: p.Member}
	if p.ExpiresInDays > 0 {
		req.ExpiresInDays = &p.ExpiresInDays
	}
	return c.Customers.ConnectCustomer(ctx, p.ID, req, opts...)
}

// AccessOption narrows an Access check.
type AccessOption func(*accessConfig)

type accessConfig struct{ member string }

// WithMember also checks that the person (your own id of them) is a member of the customer.
func WithMember(id string) AccessOption { return func(a *accessConfig) { a.member = id } }

// Access says whether the customer may use the service now, by subscription, payment and the meters given. Cached when
// the client has a cache.
func (c *Client) Access(ctx context.Context, customer string, meters []string, opts ...AccessOption) (*CustomerAccess, error) {
	var ac accessConfig
	for _, o := range opts {
		o(&ac)
	}
	if c.cache != nil {
		return c.cache.Access(ctx, customer, meters, ac.member)
	}
	return c.Customers.GetCustomerAccess(ctx, customer, &GetCustomerAccessParams{Meters: strings.Join(meters, ","), Member: ac.member})
}

// RecordUsage records one usage event; with a cache, it counts it into the cached answers too.
func (c *Client) RecordUsage(ctx context.Context, ev IncomingEvent) (EventResult, error) {
	if c.cache != nil {
		return c.cache.RecordUsage(ctx, ev)
	}
	results, err := c.Events.Ingest(ctx, []IncomingEvent{ev})
	if err != nil || len(results) == 0 {
		return EventResult{}, err
	}
	return results[0], nil
}

// HandleWebhook invalidates cached answers for a verified webhook event; false without a cache or for an event that
// changes nothing cached.
func (c *Client) HandleWebhook(ctx context.Context, ev *WebhookEvent) bool {
	return c.cache != nil && c.cache.HandleWebhook(ctx, ev)
}
