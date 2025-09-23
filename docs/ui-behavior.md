## UI Behavior

### Dashboard tabs
- Users: shows SQLite tables and sample rows (read-only overview)
- Channels: create/list channels; optional logo upload
- INBOX: fetch emails via IMAP; upsert into Messages; also ensures Contacts
- Emails: list unique contacts (email + name), allow editing
- Site: renders these docs

### INBOX (Messages)
- Connects to IMAP using config.local.php
- For each fetched message:
  - Build/normalize message_id (fallback hash if missing)
  - Upsert into Messages by message_id
  - Parse from address into from_email and from_name
  - Insert into Emails if contact absent (by email)

### Emails (Contacts)
- Unique by email; name is editable inline
- No deduping by name; email is the key


