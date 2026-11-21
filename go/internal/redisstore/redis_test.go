// Package redisstore runs the go-redis adapter from the README against a real Redis. It is a module of its own, like
// internal/contract, so the SDK's go.mod gains no dependency. The test is skipped unless PAYRUDDER_TEST_REDIS_URL is set:
//
//	docker run -d --rm -p 6390:6379 redis:7-alpine && PAYRUDDER_TEST_REDIS_URL=redis://localhost:6390 go test ./...
package redisstore

import (
	"context"
	"crypto/rand"
	"encoding/hex"
	"encoding/json"
	"errors"
	"net/http"
	"net/http/httptest"
	"os"
	"sync/atomic"
	"testing"
	"time"

	payrudder "github.com/nafrucom/payrudder-sdks/go"
	"github.com/redis/go-redis/v9"
)

// The adapter as the README shows it.
type redisStore struct{ c *redis.Client }

func (s redisStore) Get(ctx context.Context, k string) (string, bool, error) {
	v, err := s.c.Get(ctx, k).Result()
	if errors.Is(err, redis.Nil) {
		return "", false, nil
	}
	return v, err == nil, err
}
func (s redisStore) Set(ctx context.Context, k, v string, ttl time.Duration) error {
	return s.c.Set(ctx, k, v, ttl).Err()
}
func (s redisStore) Delete(ctx context.Context, k string) error { return s.c.Del(ctx, k).Err() }

var _ payrudder.CacheStore = redisStore{}

func open(t *testing.T) redisStore {
	t.Helper()
	url := os.Getenv("PAYRUDDER_TEST_REDIS_URL")
	if url == "" {
		t.Skip("PAYRUDDER_TEST_REDIS_URL is not set")
	}
	opt, err := redis.ParseURL(url)
	if err != nil {
		t.Fatal(err)
	}
	c := redis.NewClient(opt)
	t.Cleanup(func() { _ = c.Close() })
	return redisStore{c}
}

func key(k string) string {
	b := make([]byte, 8)
	_, _ = rand.Read(b)
	return "payrudder-sdk-test:go:" + hex.EncodeToString(b) + ":" + k
}

func TestSetGetDeleteAndExpiry(t *testing.T) {
	s := open(t)
	ctx := context.Background()
	k := key("a")
	expect := func(want string, wantOK bool) {
		t.Helper()
		v, ok, err := s.Get(ctx, k)
		if err != nil || ok != wantOK || v != want {
			t.Fatalf("Get = %q, %v, %v; want %q, %v", v, ok, err, want, wantOK)
		}
	}
	expect("", false)
	if err := s.Set(ctx, k, "v1", time.Minute); err != nil {
		t.Fatal(err)
	}
	expect("v1", true)
	if err := s.Delete(ctx, k); err != nil {
		t.Fatal(err)
	}
	expect("", false)
	if err := s.Set(ctx, k, "short", 150*time.Millisecond); err != nil {
		t.Fatal(err)
	}
	expect("short", true)
	time.Sleep(400 * time.Millisecond)
	expect("", false)
}

func TestAWebhookInvalidatesWhatAnotherInstanceCached(t *testing.T) {
	s := open(t)
	ctx := context.Background()
	var asked atomic.Int32
	var used atomic.Value
	used.Store("8")
	api := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/v1/customers/cus/entitlements/api" {
			http.NotFound(w, r)
			return
		}
		asked.Add(1)
		w.Header().Set("Content-Type", "application/json")
		_ = json.NewEncoder(w).Encode(map[string]any{"customer_external_id": "cus", "meter_code": "api", "used": used.Load(), "allowed": true})
	}))
	defer api.Close()
	client, err := payrudder.New(payrudder.WithAPIKey("prk_test_abcdefgh_secret"), payrudder.WithBaseURL(api.URL))
	if err != nil {
		t.Fatal(err)
	}
	fail := func(err error) { t.Errorf("store error: %v", err) }
	opts := payrudder.CacheOptions{Store: s, Prefix: key("cache") + ":", OnError: fail}
	one, two := payrudder.NewCache(client, opts), payrudder.NewCache(client, opts)

	read := func(c *payrudder.Cache) string {
		t.Helper()
		st, err := c.Entitlement(ctx, "cus", "api")
		if err != nil {
			t.Fatal(err)
		}
		return st.Used
	}
	if read(one) != "8" || read(two) != "8" || asked.Load() != 1 {
		t.Fatalf("the second instance did not read the first one's entry: asked %d times", asked.Load())
	}
	used.Store("9")
	if !two.HandleWebhook(ctx, &payrudder.WebhookEvent{Type: payrudder.EventEntitlementUpdated, Data: json.RawMessage(`{"customer_external_id":"cus"}`)}) {
		t.Fatal("HandleWebhook did nothing")
	}
	if got := read(one); got != "9" || asked.Load() != 2 {
		t.Fatalf("after the webhook: used %s, asked %d times; want 9 and 2", got, asked.Load())
	}
}
