# IP addresses

A server can have more than one IP address. **System → IP Addresses** sets
which of them websites may use and who may use each one. Each website then
answers on one address, and its DNS records point there.

Until you add an address, nothing changes: every website answers on every
address of the server, as before.

## The pool

The page lists the addresses on the server's network interfaces (drust reads
them; loopback and link-local addresses are left out). Press **Use** next to
one, then **Add to pool**.

- **Shared**: any website may use it.
- **Dedicated**: only the websites of one user, or of one reseller. A reseller
  sees the addresses dedicated to them on the same page and gives each one to
  a customer, or keeps it for their own websites. A reseller's customers
  cannot pick an address the reseller has not given them.
- **Main IP**: the first shared address you add. Every website without its own
  choice uses it. **Make main IP** on another shared address moves those
  websites, and changes their DNS records that held the old main address.
- **Public (NAT) address**: cloud servers usually have a private address on
  the interface (such as `10.0.0.5`) and a public one the provider forwards to
  it. Add the private address and enter the public one here: DNS gets the
  public address, the gateway answers on the private one.
- **Site for the bare IP**: the website shown when someone opens the address
  itself (`http://203.0.113.10/`) instead of a domain.

An address used by websites cannot be removed, and the main IP cannot be
removed while other addresses are in the pool. An address cannot be dedicated
while websites of another owner use it.

## A website's address

On a website's **Manage** page, the **IP address** card offers the addresses
its owner may use: the shared ones and the ones dedicated to the owner. Pick
one and press **Change IP**.

When this server hosts the website's DNS zone, the change also rewrites:

- the A record (AAAA for an IPv6 address) of the domain, `www.` and the
  site's other aliases;
- every other record in the zone that held the old address, except the names
  of other websites (they keep their own address) and the mail host that the
  zone's MX record names (mail has its own sending IP, below).

The card lists what changed. When the zone is somewhere else (Cloudflare, the
registrar), change the A record there; the card says so.

An alias, including `www.`, always answers on its main website's address.

## How the gateway serves it

The gateway keeps listening on all addresses (`0.0.0.0:80` and `:443`), so an
address added later works without a restart. For each connection it checks
the address the visitor connected to:

- On a **dedicated** address only the websites on that address answer. Another
  domain pointed at it gets "Site not found".
- On a **shared** address every website answers, including one that moved to
  a dedicated address, so visitors whose DNS still has the old address keep
  reaching it.
- A request for the bare address gets that address's **Site for the bare IP**.
- Addresses outside the pool (such as `127.0.0.1`) work as before.

## Mail

Mail already has its own sending IPs: **Email → Mail SSL → Mail IPs** adds an
address with its own HELO hostname, and each mail domain can send from one
(Postfix uses a separate transport per address with `smtp_bind_address` and
`smtp_helo_name`; the Mail DNS Guide shows the matching MX and SPF). An address
the pool dedicates to someone can only send the mail of that owner's domains.

## Limits

- HTTPS for a bare address (no domain, no SNI) gets the gateway's fallback
  certificate, not the default site's.
- Mail on an address behind NAT is not supported: Postfix must bind the
  address the Mail DNS Guide puts in SPF.
- Windows cannot list its addresses yet; type the address on the page.
