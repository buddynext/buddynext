# Running BuddyNext on a Multisite Network

BuddyNext runs one community per network, on the network's main site. Every other site in the network stays exactly as it was: no tables, no pages, no settings, no extra load.

## Why this matters

A network can hold hundreds of sites. Running a separate community on each would multiply tables, jobs and admin screens. One community on the main site keeps the network light and gives members a single place to meet.

## Set it up

1. Install BuddyNext and BuddyNext Pro on the network.
2. Go to **Network Admin > Plugins** and choose **Network Activate** for BuddyNext first, then for BuddyNext Pro. Pro needs the free plugin active before it starts.
3. Open the main site's wp-admin and run the setup wizard there. All BuddyNext settings live on the main site. The line under BuddyNext in Network Admin > Plugins links to them.

## Who is a member

A member is a user with a role on the main site, which is how WordPress itself counts people on a site. A user who already belongs to another site of the network becomes a member the first time they post, comment, react, follow, connect or join a space in the community. They get the main site's default role, and your registration mode still applies: if sign-ups need approval, they wait in the same pending list as a new sign-up; if the community is closed or invite-only, they are turned away. Just browsing never adds anyone.

Users created in Network Admin, or activated from a blog sign-up, are not members until they take that first action, and they receive no welcome email until then.

## What follows the network settings

- Banned names, banned email domains and limited email domains from Network Admin > Settings apply to community sign-ups.
- Upload size and quota keep BuddyNext's own limits.

## Removing and deleting people

- Removing someone from the main site, or rejecting a pending sign-up, removes their community data. Their account stays if they also belong to another site.
- A person who deletes their own account, or whom a super admin deletes, loses the account too when they belong to no other site.

## Good to know

- Checkout and membership always run on the main site.
- Blocks and shortcodes placed in the content of other sites render nothing.
- Privacy exports and erasures include community data when they are requested on the main site.
- Changing which site is the main site is not supported. Switching back restores the community.
- Multi-network installs run one community per network.
- Uninstalling follows the main site's "delete data" choice and cleans the main site only.
