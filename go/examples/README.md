# Go examples

Five small programs, one directory each: [connect a customer and check access](connect-and-check/main.go), [send a usage event](send-usage/main.go), [create a customer](create-customer/main.go), [list invoices](list-invoices/main.go) and [verify a webhook](verify-webhook/main.go).

```sh
export PAYRUDDER_API_KEY=prk_test_...             # a sandbox key
export PAYRUDDER_BASE_URL=http://localhost:42800 # the default
cd sdk/go && go run ./examples/send-usage
```

`verify-webhook` also needs `PAYRUDDER_WEBHOOK_SECRET`, the secret of the endpoint that posts to it. In your own module, `go get github.com/nafrucom/payrudder-sdks/go` first.

They are not run in CI (they need a gateway); they are **built, vetted and linted** with the module (`go build ./...`, `go vet ./...`, golangci-lint), so a renamed method or field fails the build.
