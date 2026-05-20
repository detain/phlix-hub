# Hub Invite Links API Reference

Base URL: `https://hub.phlix.media` (production) or `http://localhost:8800` (development)

## Authentication

All endpoints require a valid Hub access token in the `Authorization` header:
```
Authorization: Bearer <access_token>
```

## Endpoints

### Create Invite Link

Creates a new invite link for sharing a library.

**Request**
```http
POST /api/v1/me/invite-links
Content-Type: application/json

{
    "server_id": "uuid",
    "library_id": "uuid",        // optional, null for all libraries
    "permission": "read",       // "read" or "readwrite"
    "max_uses": 1,               // number of times link can be used
    "expires_in": 604800        // seconds until expiry (default: 7 days)
}
```

**Response (201 Created)**
```json
{
    "url": "https://hub.phlix.media/invite/eyJ...",
    "expires_at": 1715000000,
    "id": "uuid"
}
```

**Error Responses**
- `400 Bad Request` — Missing or invalid parameters
- `401 Unauthorized` — Invalid or missing auth token
- `403 Forbidden` — User doesn't own the server

---

### List Invite Links

Returns all invite links created by the authenticated user.

**Request**
```http
GET /api/v1/me/invite-links
```

**Response (200 OK)**
```json
{
    "invite_links": [
        {
            "id": "uuid",
            "owner_user_id": "uuid",
            "server_id": "uuid",
            "library_id": "uuid",
            "permission": "read",
            "max_uses": 5,
            "use_count": 2,
            "expires_at": 1715000000,
            "created_at": 1709000000,
            "url": "https://hub.phlix.media/invite/eyJ..."
        }
    ]
}
```

**Error Responses**
- `401 Unauthorized` — Invalid or missing auth token

---

### Revoke Invite Link

Revokes an invite link, preventing further use.

**Request**
```http
DELETE /api/v1/me/invite-links/{id}
```

**Response**
- `204 No Content` — Successfully revoked

**Error Responses**
- `401 Unauthorized` — Invalid or missing auth token
- `403 Forbidden` — User doesn't own this invite link
- `404 Not Found` — Invite link not found

---

### Accept Invite (Public)

Renders the invite acceptance page for unauthenticated users, or returns token info for authenticated users.

**Request**
```http
GET /invite/{token}
```

**Response (200 OK)**
```json
{
    "token": "eyJ...",
    "is_authenticated": true
}
```

---

## Data Types

### InviteLink
| Field | Type | Description |
|-------|------|-------------|
| `id` | string (UUID) | Unique identifier |
| `owner_user_id` | string (UUID) | User who created the link |
| `server_id` | string (UUID) | Server containing the library |
| `library_id` | string (UUID) \| null | Library ID, or null for all |
| `permission` | string | `"read"` or `"readwrite"` |
| `max_uses` | integer | Maximum redemption count |
| `use_count` | integer | Current redemption count |
| `expires_at` | integer \| null | UNIX timestamp, or null |
| `created_at` | integer | UNIX timestamp |
| `url` | string | Full invite URL |

## Client Implementation Notes

1. **Copy button**: Extract the `url` field and implement a "Copy to clipboard" feature
2. **Expiry display**: Convert `expires_at` to a human-readable format for the UI
3. **Link status**: Calculate link status from `use_count >= max_uses` or `expires_at < now`
4. **Redemption flow**: After successful creation, redirect user to the link management page

## Example: Create Invite Link (JavaScript)

```javascript
async function createInviteLink(serverId, libraryId) {
    const response = await fetch('/api/v1/me/invite-links', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Authorization': `Bearer ${getAccessToken()}`
        },
        body: JSON.stringify({
            server_id: serverId,
            library_id: libraryId,
            permission: 'read',
            max_uses: 5,
            expires_in: 604800 // 7 days
        })
    });

    if (response.ok) {
        const { url, expires_at, id } = await response.json();
        return { url, expiresAt: expires_at, id };
    }

    throw new Error('Failed to create invite link');
}
```