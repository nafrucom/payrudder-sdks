import { Payrudder } from "@payrudder/sdk";

// A sign-in hands Payrudder your user; a request checks whether that user may go on, and what to tell them.
const payrudder = new Payrudder({ cache: {} });
const user = { id: "user_42", name: "Ada Lovelace", email: "ada@example.com" };

const { url, created } = await payrudder.connect({ id: user.id, name: user.name, email: user.email });
console.log(created ? "created" : "up to date", "- customer page:", url);

const access = await payrudder.access(user.id, "api_calls");
console.log(access.allowed ? "allowed" : "denied", access.reason);
for (const n of access.notices) console.log(`[${n.severity}] ${n.code}${n.meter_code ? " on " + n.meter_code : ""}${n.at ? " at " + n.at : ""}`);
