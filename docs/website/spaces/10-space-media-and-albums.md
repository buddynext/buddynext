# Space Media and Albums

A space can hold a gallery of its own. The Media tab on a space collects the photos and videos shared in that space, and its Albums view lets the space keep named sets - "Meetup photos," "Product shots," "Season one" - that belong to the space rather than to any one member.

This is the space-level counterpart to [Profile Media and Albums](../members/10-profile-media-and-albums.md). The two work the same way on purpose: same gallery, same uploader, same album controls. What changes is who owns the album and who is allowed to see it.

## Why use it

A community space accumulates photos whether or not anyone organises them. Someone posts pictures from an event, someone else adds a few more the next week, and a month later nobody can find any of them - they are scattered through the feed in date order, mixed with everything else that was said.

An album fixes that. It gives the space a place to keep the pictures from an event together, in the order the space wants them, reachable long after the posts that carried them have scrolled away. For a photography space, a local group that meets in person, a product community, or a class, that archive is often the most valuable thing the space has.

It also removes an awkward workaround. Before, the only albums BuddyNext had were personal ones, so a space's shared photos had to live in some member's profile gallery. That made one person the owner of the group's memories, and it meant those photos left with them.

## Turning the Media tab on

The Media tab is off by default and is enabled per space, so a space that is only ever going to be a discussion does not carry a gallery it never uses.

1. Open the space and go to **Settings**.
2. Turn on the **Media** tab.
3. Save.

The tab appears in the space's tab strip next to Feed, Members and About.

> **Note:** Space media needs the media engine (WPMediaVerse) active on the site, and the site-wide media integration switched on under **BuddyNext > Integrations**. Without those the tab does not appear even when the per-space setting is on.

## The two views

The Media tab opens with a sub-nav holding two views:

- **Media** - a flat grid of everything shared in this space, gathered from the space's own posts. Anything a member attaches to a post here shows up in this grid without them doing anything extra.
- **Albums** - the space's own albums, each with a cover and an item count.

## Working with space albums

### Creating an album

In the Albums view, choose **New album**, give it a name, and save. Members who can post in the space can create one; a space owner can restrict album creation to the space's organisers if they would rather keep the gallery curated.

### Adding photos

Open an album and upload into it, the same way as a personal album. You can also add media that is already in the space.

### Reordering and removing

Drag items to set the order the album shows them in. Removing an item from an album takes it out of that album; it does not delete the underlying photo, and anything still attached to a post stays on that post.

When removing a photo would empty part of a space album, BuddyNext says so before it happens, so nobody clears out a set by accident.

### Who can edit

- **Anyone who can see the space** can view its albums.
- **Anyone who can post in the space** can create an album and upload into one.
- **The album's creator, and the space's admins and moderators**, can rename, reorder, and delete.

A member who did not create an album can look through it but cannot rearrange it.

## Privacy

A space album's audience is the space. That is the whole rule, and it is worth being precise about because it is not the same as a personal album's privacy setting.

- In an **open** space, the albums are visible to anyone who can see the space, including logged-out visitors.
- In a **private** or **secret** space, the albums are visible only to members of that space. A non-member gets nothing - not a partial listing, not an album with hidden contents, nothing.
- Private and secret space albums do not appear in search for people who cannot see the space.

A space album does not carry a separate per-album privacy control, because it would be misleading: the space already decides the audience, and a "public" album inside a secret space would be a promise BuddyNext could not keep.

## Where the photos come from

The Media grid is derived from posts, so it fills up on its own as the space is used. Albums are deliberate: somebody has to make one and put things in it. A photo can be in both - shared to the space's feed and also filed in an album - and it is stored once either way.

## For developers

Space albums are reachable over REST under the space:

```
GET  /wp-json/buddynext/v1/spaces/{id}/albums
POST /wp-json/buddynext/v1/spaces/{id}/albums
```

The per-album routes are shared with personal albums and take an album id, because an album already knows whether it belongs to a space:

```
GET    /wp-json/buddynext/v1/albums/{id}
POST   /wp-json/buddynext/v1/albums/{id}
DELETE /wp-json/buddynext/v1/albums/{id}
```

The listing route is readable without authentication so an open space's albums load for a logged-out visitor, exactly as that space's media does. Permission is applied to the result rather than the request: a viewer who cannot see the space receives an empty list.

See the [REST API reference](../developer-guide/14-rest-contract.md) for the full parameter and response shapes.

## Related

- [Profile Media and Albums](../members/10-profile-media-and-albums.md) - the personal gallery this mirrors
- [Space Types and Privacy](03-space-types-and-privacy.md) - what open, private and secret mean
- [Roles and Moderators](05-roles-and-moderators.md) - who counts as a space admin or moderator
