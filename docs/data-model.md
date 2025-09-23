## Data model

### Users
- id (PK)
- username (unique)
- password_hash
- created_at

### Channels
- id (PK)
- name
- homepage_url
- logo_path (nullable)
- created_at

### Emails (Contacts)
- id (PK)
- email (unique)
- name (nullable)
- created_at

### Messages (Inbox)
- id (PK)
- message_id (unique)
- from_name (nullable)
- from_email
- subject (nullable)
- mail_date (text)
- snippet (nullable)
- created_at

### Email Statuses (reserved for future)
- id (PK)
- key (unique)
- label

### Email Responses
- id (PK)
- email_id (FK -> Emails.id)
- body (text)
- sent_via (text)
- created_at


