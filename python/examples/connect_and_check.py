"""Hand Payrudder a user of your app, then check whether that user may go on and what to tell them."""

from payrudder import Payrudder

client = Payrudder(cache={})

session = client.connect(id="user_42", name="Ada Lovelace", email="ada@example.com")
print("customer page:", session["url"])

access = client.access("user_42", "api_calls")
print("allowed" if access["allowed"] else "denied", access["reason"])
for n in access["notices"]:
    print(f"[{n['severity']}] {n['code']} {n['meter_code']}")
