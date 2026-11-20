/**
 * Write hub title prefill.
 *
 * The composer's Article tab sends the title a member typed as ?bn_title=.
 * The server sanitises it and renders it on the hub frame as
 * data-bn-prefill-title (MemberBlogBridge::write_hub_prefill_title()); this
 * puts it in Member Blog's title field when that field is still empty. It
 * never overwrites a title that is already there, sets the value as text
 * (no HTML), and fires no events, so Member Blog does not treat the form as
 * edited until the member writes.
 *
 * @package BuddyNext
 */

const hub = document.querySelector( '.bn-blog-write[data-bn-prefill-title]' );
const field = document.getElementById( 'bp_member_blog_post_title' );

if ( hub && field && '' === field.value.trim() ) {
	field.value = hub.dataset.bnPrefillTitle || '';
}
