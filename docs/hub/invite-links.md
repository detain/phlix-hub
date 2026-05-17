# Invite Links

Phlex Hub allows you to share your media libraries with friends and family using invite links. Instead of entering their email address, you can generate a special link that grants them instant access to your library.

## How Invite Links Work

Invite links are secure, tokenized URLs that:
- Can be shared via messaging apps, email, or social media
- Grant immediate access to the specified library (no approval needed)
- Work with friends who already have a Hub account
- Can be set to expire after a certain time
- Can be limited to a certain number of uses (great for families)

## Creating an Invite Link

1. Go to **My Servers** and select a server
2. Find the library you want to share
3. Click the **Share** button
4. Select **Create Invite Link**
5. Configure the link settings:
   - **Permission**: Read only or Read/Write
   - **Max uses**: How many times the link can be used (default: 1)
   - **Expires in**: How long the link is valid (default: 7 days)
6. Click **Create Link**
7. Copy and share the link with your friend

## Using an Invite Link

When someone clicks your invite link:

1. If they're not logged in, they'll be prompted to log in or create an account
2. After logging in, they'll see the library immediately in their **Shared with Me** section
3. They can start browsing and playing content right away

## Managing Invite Links

Go to **Manage Shares** to see all invite links you've created. From there you can:
- See who has used the link
- Copy the link again
- Revoke the link (prevents further use)

## Invite Link vs Email Sharing

| Feature | Invite Link | Email Sharing |
|---------|-------------|---------------|
| Setup required by recipient | None (just log in) | Must have Hub account |
| Instant access | Yes | Yes |
| Multiple uses | Yes (configurable) | No |
| Expiration | Yes (configurable) | Optional |
| Can revoke | Yes | Yes |

## Security Features

- **Token-based**: Links use cryptographically signed tokens that can't be guessed
- **SHA-256 hashed**: The actual token is hashed in our database to prevent enumeration
- **One-time or limited use**: Control how many people can use each link
- **Owner-only creation**: Only library owners can create invite links
- **Instant revocation**: Revoke access at any time from your dashboard

## Frequently Asked Questions

**What if my friend doesn't have a Hub account?**
They'll be prompted to create one when they click the invite link. It's free!

**Can I create a link for all my libraries at once?**
Yes! When creating an invite link, leave the library selection blank to share access to all your libraries on that server.

**Can I use the same link for multiple friends?**
Yes, set "Max uses" to a higher number to allow multiple people to use the same link.

**What happens when a link expires?**
The link simply stops working. Anyone who already accepted the access keeps it unless you revoke their share.

**Can I see who used my invite link?**
Yes, the manage shares page shows usage statistics for each link.

**Are invite links safe?**
Yes! The tokens are cryptographically signed and hashed in our database. Even if someone intercepts the link, they can't determine your account details from it.