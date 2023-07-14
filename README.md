# MigrateUserAccount

Allows users to reclaim their account on a wiki by verifying their identity on an external wiki. This is especially useful if you are forking a wiki from another wiki farm, but don't have access to the old wiki's user database.

> This extension is designed to only work on users that have an empty password field in your MediaWiki [user table](https://www.mediawiki.org/wiki/Manual:User_table), to avoid impacting regular users. You can generate entries in your user table using the [grabber scripts on MediaWiki.org](https://www.mediawiki.org/wiki/Manual:Grabbers), specifically the [`populateUserTable.php`](https://gerrit.wikimedia.org/g/mediawiki/tools/grabbers/%2B/HEAD/populateUserTable.php) script.

## Installation

1. Clone this repository to your MediaWiki installation's `extensions` folder using `git clone https://github.com/weirdgloop/mediawiki-extensions-MigrateUserAccount.git -b MigrateUserAccount`
2. Modify your `LocalSettings.php` file and add:

```php
wfLoadExtension( 'MigrateUserAccount' );

# These two options must be configured for this extension to function correctly:
$wgMUARemoteWikiContentPath = '';
$wgMUARemoteWikiAPI = '';
```

## Configuration
This extension can be configured using the `LocalSettings.php` file in your MediaWiki installation.

| Variable                      | Type   | Description                                                                                                                                                                                                                                                          | Default |
|-------------------------------|--------|----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|---------|
| `$wgMUARemoteWikiContentPath` | string | URL to the remote wiki's content path (with trailing slash). For FANDOM sites, this is something like `https://community.fandom.com/wiki/`                                                                                                                           |         |
| `$wgMUARemoteWikiAPI`         | string | URL to the remote wiki's API. For FANDOM sites, this is something like `https://community.fandom.com/api.php`                                                                                                                                                        |         |
| `$wgMUAOverrideLoginPrompt`   | bool   | Whether the default `MediaWiki:Loginprompt` message on Special:Login should be overriden with a message directing users to migrate their accounts if necessary *(if you prefer, set this to false and set your own message in `MediaWiki:Loginprompt` on your wiki)* | `true`  |
| `$wgMUALogToWiki`             | bool   | Whether we should log to a public log on `Special:Log` when a user migrates their account                                                                                                                                                                            | `true`  |

## License

This extension is licensed under GPLv3 - [see the license file](/LICENSE).

It is inspired by an extension called [UserMigration](https://gitlab.com/hydrawiki/extensions/usermigration/), which is no longer maintained. That project was maintained by foxlit, Alexia E. Smith (Curse Inc.), and others.
