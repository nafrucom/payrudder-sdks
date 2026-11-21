package payrudder

import (
	"context"
	"encoding/json"
	"io"
	"net/http"
	"strings"
	"sync"
	"testing"
	"time"
)

type accessServer struct {
	mu       sync.Mutex
	accesses int
	urls     []string
	bodies   []string
}

func (s *accessServer) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	b, _ := io.ReadAll(r.Body)
	s.mu.Lock()
	s.urls = append(s.urls, r.Method+" "+r.URL.RequestURI())
	s.bodies = append(s.bodies, string(b))
	s.mu.Unlock()
	w.Header().Set("Content-Type", "application/json")
	switch {
	case strings.HasSuffix(r.URL.Path, "/session"):
		_, _ = io.WriteString(w, `{"url":"https://x/c/tok","expires_at":"2026-11-22T00:00:00Z","customer":{"external_id":"u1"},"created":true,"billing_owner":true}`)
	case r.URL.Path == "/v1/events":
		_, _ = io.WriteString(w, `{"results":[{"status":"accepted"}]}`)
	default:
		s.mu.Lock()
		s.accesses++
		s.mu.Unlock()
		_, _ = io.WriteString(w, `{"customer_external_id":"u1","allowed":true,"reason":"ok","subscription":{"id":"s1","state":"SUBSCRIPTION_STATE_ACTIVE","plan_code":"pro","trial_ends_at":null,"cancel_at_period_end":false},`+
			`"meters":[{"customer_external_id":"u1","meter_code":"api","used":"9","limit":"10","unlimited":false,"allowed":true,"mode":"hard","over_limit":false,"suspended":false}],"notices":[{"code":"limit_near","severity":"warning","meter_code":"api","at":null}]}`)
	}
}

func TestConnectRequest(t *testing.T) {
	srv := &accessServer{}
	c, _ := newTestClient(t, srv)
	out, err := c.Connect(context.Background(), ConnectParams{ID: "u1", Name: "Acme", Plan: "pro", Member: &ConnectMember{ID: "m1", Name: "Olga"}})
	if err != nil || out.URL == nil || *out.URL != "https://x/c/tok" {
		t.Fatalf("connect: %v %+v", err, out)
	}
	var body map[string]any
	_ = json.Unmarshal([]byte(srv.bodies[0]), &body)
	if srv.urls[0] != "PUT /v1/customers/u1/session" || body["plan_code"] != "pro" || body["member"].(map[string]any)["id"] != "m1" {
		t.Fatalf("request: %v %v", srv.urls, body)
	}
}

func TestAccessNoCache(t *testing.T) {
	srv := &accessServer{}
	c, _ := newTestClient(t, srv)
	for range 2 {
		if _, err := c.Access(context.Background(), "u1", []string{"a", "b"}); err != nil {
			t.Fatal(err)
		}
	}
	if srv.accesses != 2 || srv.urls[0] != "GET /v1/customers/u1/access?meters=a%2Cb" {
		t.Fatalf("%d %v", srv.accesses, srv.urls)
	}
}

func TestAccessCached(t *testing.T) {
	srv := &accessServer{}
	c, _ := newTestClient(t, srv, WithCache(CacheOptions{}))
	for range 2 {
		a, err := c.Access(context.Background(), "u1", []string{"api"})
		if err != nil || !a.Allowed || a.Subscription == nil || a.Subscription.PlanCode != "pro" {
			t.Fatalf("%v %+v", err, a)
		}
	}
	if srv.accesses != 1 {
		t.Fatalf("accesses: %d", srv.accesses)
	}
}

func TestAccessMeterOrder(t *testing.T) {
	srv := &accessServer{}
	c, _ := newTestClient(t, srv, WithCache(CacheOptions{}))
	_, _ = c.Access(context.Background(), "u1", []string{"a", "b"})
	_, _ = c.Access(context.Background(), "u1", []string{"b", "a"})
	if srv.accesses != 1 {
		t.Fatalf("accesses: %d", srv.accesses)
	}
}

func TestAccessInvalidatedByInvoicePaid(t *testing.T) {
	srv := &accessServer{}
	c, _ := newTestClient(t, srv, WithCache(CacheOptions{}))
	ctx := context.Background()
	_, _ = c.Access(ctx, "u1", []string{"api"})
	if !c.HandleWebhook(ctx, &WebhookEvent{Type: "invoice.paid", Data: json.RawMessage(`{"customer_external_id":"u1"}`)}) {
		t.Fatal("invoice.paid did nothing")
	}
	_, _ = c.Access(ctx, "u1", []string{"api"})
	if srv.accesses != 2 {
		t.Fatalf("accesses: %d", srv.accesses)
	}
}

func TestRecordUsageFlipsAccess(t *testing.T) {
	srv := &accessServer{}
	c, _ := newTestClient(t, srv, WithCache(CacheOptions{}))
	ctx := context.Background()
	_, _ = c.Access(ctx, "u1", []string{"api"})
	if _, err := c.RecordUsage(ctx, IncomingEvent{CustomerExternalID: "u1", MeterCode: "api", Quantity: "1", EventTime: time.Now()}); err != nil {
		t.Fatal(err)
	}
	a, err := c.Access(ctx, "u1", []string{"api"})
	if err != nil || a.Allowed || a.Reason != "limit_reached" || a.Meters[0].Used != "10" || srv.accesses != 1 ||
		len(a.Notices) != 1 || a.Notices[0].Code != "limit_reached" || a.Notices[0].MeterCode != "api" {
		t.Fatalf("%v %+v accesses %d", err, a, srv.accesses)
	}
}
