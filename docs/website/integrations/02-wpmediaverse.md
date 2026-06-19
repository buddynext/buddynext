# WPMediaVerse

WPMediaVerse is the companion plugin that brings private messaging and richer media to your community. Turn it on, and members can message each other one to one and attach photos and other media to their posts and conversations - all without leaving BuddyNext.

BuddyNext is the social layer your members already know; WPMediaVerse is the messaging and media engine working quietly underneath it. Because BuddyNext presents that engine through its own screens, sending a message or sharing a photo feels like a native part of the community, not a second plugin.

## Why use it

A community without private messaging is missing the most basic way for members to talk one to one. Members expect to message someone the way they would on Facebook or LinkedIn, and to share an image as easily as they share text. BuddyNext provides the front end for both, but the actual message store and media library live in WPMediaVerse.

For a community owner, this means messaging is a one-step add-on rather than a separate build. Install the companion, and a Messages area, a Media link, and media attachments all appear inside the community, wired to BuddyNext's own notifications, blocking, and privacy rules. For members, it means they can start a conversation, share a photo, and get notified about replies without ever touching a second plugin's interface.

The real scenario it solves: someone wants to follow up privately after seeing a post, send a quick question, or share an image with another member. None of that is possible until the messaging engine is present. WPMediaVerse is what turns BuddyNext from a public feed into a place where people can also talk privately.

## How it works (for members)

Once WPMediaVerse is active, these become available to members inside BuddyNext:

### Direct messages

Members can open a private conversation with another member and exchange messages. The full member experience - opening a conversation, the inbox, message requests, and unread counts - is covered in Direct Messaging. New messages raise a BuddyNext notification (bell and email, according to each member's preferences), with no duplicate notice from the engine.

### Media in posts and messages

With the companion active, members can attach photos and other media to their activity posts, and media can be shared inside conversations. Media opens in a built-in viewer for reactions and comments, all rendered as part of the BuddyNext feed rather than a separate media interface.

### Media link in the sidebar

A **Media** link appears in the BuddyNext left navigation rail, pointing to the community's media landing page so members can browse shared media in one place.

## Setting it up (for owners)

### Install the companion (1-click)

WPMediaVerse installs from inside BuddyNext - no manual upload or plugin search.

1. Go to **BuddyNext > Settings > Integrations**.
2. Find **MediaVerse** under **Companion plugins**. Its description reads "Direct messaging, media galleries, and social feeds."
3. Select **Install free**. BuddyNext pulls the plugin from the Wbcom store and installs it for you. The card then shows **Active**.

If the plugin is already installed but switched off, the same row shows an **Activate** link instead. The status badge tells you which state you are in: Active, Inactive, or Not installed.

> **Note:** The 1-click install needs a site administrator with permission to install and activate plugins. If you do not see the **Install free** button, your account does not have that capability.

> _Screenshot: BuddyNext Settings > Integrations showing the MediaVerse companion row with the Install free button - captured in the image pass._

### What becomes available once active

The moment WPMediaVerse is active alongside BuddyNext, the bridge between them attaches automatically. There is nothing further to configure for basic messaging - the engine's own chat panel, standalone messages page, and notifications step aside so BuddyNext owns the experience. Members get direct messaging, media in posts, and the Media sidebar link with no extra setup.

There are no BuddyNext settings specific to this companion. Who can message whom is controlled by your existing BuddyNext privacy and moderation rules (see Direct Messaging and Blocking and Muting), not by a separate WPMediaVerse panel.

## Good to know

- **Blocking is honored at the messaging layer.** If a member has blocked someone, that person cannot send them a direct message - the block is checked and the send is refused before any message is stored. This is the single enforcement point for message blocking, so it holds regardless of how the message was started.
- **Muting and restricting are respected too.** If a member mutes or restricts another member rather than fully blocking them, an incoming message is still stored but does not interrupt the recipient - no bell notification, no feed signal.
- **No duplicate notifications.** Because BuddyNext owns the notification path, the engine does not raise its own competing message notification. Members get one clean notification per new message.
- **Inert when not installed.** If WPMediaVerse is not active, BuddyNext simply has no messaging or media features - there is no error, no broken Media link, and no leftover Messages area. Installing the companion is what turns them on.

## Free vs Pro

The free WPMediaVerse companion gives BuddyNext everything described above: member-to-member direct messaging, media in posts and conversations, and the Media sidebar link.

WPMediaVerse Pro extends the messaging engine with:

- **Read receipts** - see when a message has been read.
- **Group messages** - conversations with more than two members.
- **Real-time delivery** - messages arrive live without waiting for a refresh.

These are engine-level upgrades. Activating WPMediaVerse Pro lights them up inside the same BuddyNext messaging experience; you do not change anything in BuddyNext itself to use them.
