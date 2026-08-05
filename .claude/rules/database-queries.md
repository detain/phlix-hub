---
paths:
  - src/Hub/**
  - src/Federation/**
  - src/Auth/**
  - src/Common/Database/**
---

# Database Access

- Use only `Workerman\MySQL\Connection` (injected `$this->db`). No PDO, no mysqli.
- **Named `:param` placeholders only** — positional `?` fails: `bindMore()` runs `array_keys()` into `bindParam()`, which rejects 0-based indices.
  ```php
  $this->db->query('SELECT id FROM servers WHERE id = :id', ['id' => $serverId]);
  ```
- Never interpolate user input into SQL strings.
- Generate ids with the local `generateUuid()` helper (8-4-4-4-12 hex); PKs are `CHAR(36)`.
- Repositories (`*Repository.php`) keep `ALLOWED_KEYS`-style allow-lists; see `src/Hub/HubSettingsRepository.php` and `src/Hub/AuditLogRepository.php`.
- Mutating auth/admin actions also call `AuditLogger`/`AuditLogRepository::log()`.
