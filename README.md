# TopicMachine Publisher

TopicMachine Publisher provides article navigation and search metadata for
WordPress sites connected to TopicMachine. It works with standard WordPress
post and page templates and inherits the theme's colors and typography.

## Install

1. Download `topicmachine-publisher.zip` from the
   [latest tagged release](https://github.com/syntaxsurge/topicmachine-wordpress/releases/latest).
2. In WordPress, visit **Plugins → Add New Plugin → Upload Plugin**. Upload the
   ZIP and activate **TopicMachine Publisher**.
3. Connect your website and WordPress Application Password in TopicMachine
   under **Publishing connections**. Use the permissions needed to edit and
   publish posts and upload media. WordPress controls the permissions for
   storing interactive article markup.
4. Open a published post with at least three sections. Its Contents disclosure
   links to the existing section anchors. The plugin adds any missing targets.

Requires WordPress 6.4 or higher and PHP 8.1 or higher.

## Site behavior

- Published posts and pages use one keyboard-accessible Contents disclosure,
  with a localized label and support for Polylang post languages.
- WordPress continues to own document titles, canonical URLs, author records,
  featured images, categories, and native publishing states.
- For articles carrying a TopicMachine publication marker, the saved excerpt
  supplies the Yoast description when an editor has left its dedicated field
  blank. On sites without an SEO integration, the plugin supplies description,
  Open Graph, and Twitter tags from the article and the current featured image.
  A theme that already owns these tags can use
  `add_filter( 'topicmachine_publisher_fallback_metadata', '__return_false' );`.
- The plugin enqueues a small, scoped stylesheet for Contents, FAQ disclosures,
  and embedded videos. It performs no public network requests on article views.
- Deactivation and removal leave posts, their media, and their stored metadata
  in place.

## Updates

WordPress checks public GitHub releases automatically. Use **Plugins → Check
for updates** on the TopicMachine Publisher row for an immediate check.
Updates install through WordPress and verify the downloaded ZIP against
the SHA-256 digest returned by GitHub. The site needs HTTPS access to the
public GitHub release service.

## Package a release

Tag a commit `vX.Y.Z` once the plugin header and `VERSION` constant match.
Create a ZIP named `topicmachine-publisher.zip` whose top level is the
`topicmachine-publisher/` plugin directory. Upload the ZIP to the corresponding
stable public GitHub release.

[TopicMachine developer documentation](https://topicmachine.com/docs)
