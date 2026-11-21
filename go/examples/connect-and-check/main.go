// Connect-and-check hands Payrudder a user of your app and checks whether that user may go on, and what to tell them.
package main

import (
	"context"
	"log"

	payrudder "github.com/nafrucom/payrudder-sdks/go"
)

func main() {
	client, err := payrudder.New(payrudder.WithCache(payrudder.CacheOptions{}))
	if err != nil {
		log.Fatal(err)
	}
	ctx := context.Background()
	session, err := client.Connect(ctx, payrudder.ConnectParams{ID: "user_42", Name: "Ada Lovelace", Email: "ada@example.com"})
	if err != nil {
		log.Fatal(err)
	}
	if session.URL != nil {
		log.Printf("customer page: %s", *session.URL)
	}
	access, err := client.Access(ctx, "user_42", []string{"api_calls"})
	if err != nil {
		log.Fatal(err)
	}
	log.Printf("allowed: %v, reason: %s", access.Allowed, access.Reason)
	for _, n := range access.Notices {
		log.Printf("[%s] %s %s", n.Severity, n.Code, n.MeterCode)
	}
}
