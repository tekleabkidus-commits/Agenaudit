# AI Evidence Extraction Contract

The AI layer only **extracts what is visible in screenshots**. It never decides whether a transaction is valid. All financial decisions are made by Laravel services and database constraints after extraction.

## Drivers

Set `AI_DRIVER=openai` for OpenAI Responses API, `AI_DRIVER=gemini` for the optional Gemini image adapter, or `AI_DRIVER=http` for a private/custom gateway.

## Free-tier Gemini and confidential financial evidence

Google's Gemini API has limited free allowances (model availability varies). Google states its **unpaid services may use submitted content to improve products and may involve human review**. The free tier is therefore **not suitable by default for confidential bank receipts, phone numbers, and account screenshots**.

For permitted synthetic tests, set `AI_DRIVER=gemini`, `GEMINI_API_KEY`, and `GEMINI_MODEL=gemini-3.5-flash-lite`. Real Agent Audit evidence remains blocked by `GEMINI_ALLOW_SENSITIVE_EVIDENCE=false` and goes to Admin manual extraction. Enabling the sensitive-data switch requires an explicit independent privacy/legal approval of the provider's terms and the user's rights over the data. For production private financial evidence, use a provider with appropriate terms or a private self-hosted OCR/vision gateway.

## Multi-image proof batches

Agent-system proof uploads accept **1–5 images of the same transaction**. The backend stores each image immutably under the same transaction but queues **one combined extraction** for all active agent proof images. The extractor must produce ONE amount, identity, timestamp, and set of balances: never sum repeated amounts from agent screenshots.

Each bank screenshot is instead verified independently, with a distinct transaction number, date and amount, and can contribute to the bank receipt total only once.

For `AI_DRIVER=http`: one screenshot retains multipart `file` input. Multi-image agent batches send multipart `files[]` plus `image_count` to the gateway. The gateway must implement the same grouped extraction and conflict rule.

All AI calls are server-side. API credentials are never exposed to the employee browser.

## Common quality object

Every response must include:

```json
{
  "quality": {
    "score": 0.98,
    "critical_confidence": 0.96,
    "issues": []
  }
}
```

If a required field cannot be read safely, return `null`/empty for that field and lower `critical_confidence`. The application will require a clearer screenshot instead of allowing the employee to type over the result.

## Agent-system screenshot

```json
{
  "quality": {"score": 0.98, "critical_confidence": 0.97, "issues": []},
  "agent_id": "AG1001",
  "agent_username": "abebe01",
  "brand_hint": "Brand 1",
  "amount": 50000,
  "transaction_at": "2026-10-07T14:25:00+03:00",
  "balance_before": 24500,
  "balance_after": 74500,
  "transaction_reference": "ATX-23872"
}
```

`brand_hint` is informational only. The authoritative brand comes from the globally unique Agent ID + username in the agent master table.

## Bank/payment screenshot

```json
{
  "quality": {"score": 0.97, "critical_confidence": 0.95, "issues": []},
  "from_bank": "CBE",
  "to_bank": "Awash Bank",
  "sender_account": "1000***4456",
  "sender_name": "Abebe Kebede",
  "receiver_account": "1000***6273",
  "receiver_name": "ABC Trading PLC",
  "amount": 25000,
  "transaction_id": "FT260712345",
  "transaction_at": "2026-10-07T14:14:00+03:00",
  "status": "completed"
}
```

The receiving account is validated independently against Admin-configured accounts. A masked account is accepted only when its visible digits + bank + receiver name uniquely resolve to an approved account for the detected brand.

## Custom HTTP gateway

When `AI_DRIVER=http`, Laravel sends multipart form data:

- `file`: private screenshot bytes
- `kind`: `agent_system` or `bank_payment`
- `schema_version`: `2026-10-07`
- `model`: configured `AI_MODEL`

The gateway must return one of the JSON objects above directly as the HTTP JSON response.

## Non-negotiable application rules after AI

- Employees cannot edit extracted values directly.
- Low quality/confidence requires a new screenshot.
- Global duplicate bank transaction ID is a hard rejection.
- Wrong/unapproved receiving account is a hard rejection and cannot be corrected.
- Check.et is secondary only; it cannot override an internal hard rejection.
