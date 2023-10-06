# MigrateUserAccount

Allows users to reclaim their account on a wiki by verifying their identity on an external wiki. This is especially useful if you are forking a wiki from another wiki farm, but don't have access to the old wiki's user database.

> This extension is designed to only work on users that have an empty password field in your MediaWiki [user table](https://www.mediawiki.org/wiki/Manual:User_table), to avoid impacting regular users. You can generate entries in your user table using the [grabber scripts on MediaWiki.org](https://www.mediawiki.org/wiki/Manual:Grabbers), specifically the [`populateUserTable.php`](https://gerrit.wikimedia.org/g/mediawiki/tools/grabbers/%2B/HEAD/populateUserTable.php) script.

## How it works
* Users can go to `Special:MigrateUserAccount` and enter their account username from the previous wiki
* As long as the account in the `user` table has an empty `user_password` field, the page will present a unique token (generated as a combination of `user:session_id` and salted with a secret provided in `$wgMUATokenSecret`)
* The user is directed to edit their user page on the previous wiki and insert this unique token into the revision content or the edit summary
* Once done, the user presses a confirmation button on our wiki, which will make an API call to check the latest revision on the previous wiki
* If the token is present, and was added by the same user, then the migration can succeed and the user can reset their password to gain access to their account

## Installation

1. Clone this repository to your MediaWiki installation's `extensions` folder using `git clone https://github.com/weirdgloop/mediawiki-extensions-MigrateUserAccount.git -b MigrateUserAccount`
2. Modify your `LocalSettings.php` file and add:

```php
wfLoadExtension( 'MigrateUserAccount' );

# These two options must be configured for this extension to function correctly:
$wgMUARemoteWikiContentPath = '';
$wgMUARemoteWikiAPI = '';

# You should also set a secret to be used in token generation (hexadecimal).
# The value below is an example, but you should use your own.
# You must keep this secret private.
$wgMUATokenSecret = '09af3bbba6a3ac5d2b7554f5a1e1495d';
```

## Configuration
This extension can be configured using the `LocalSettings.php` file in your MediaWiki installation.

| Variable                      | Type   | Description                                                                                                                                                                                                                           | Default |
|-------------------------------|--------|---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|---------|
| `$wgMUATokenSecret`           | hex    | Hex string used as a secret during token generation                                                                                                                                                                                   |         |
| `$wgMUARemoteWikiContentPath` | string | URL to the remote wiki's content path (with trailing slash). For FANDOM sites, this is something like `https://community.fandom.com/wiki/`                                                                                            |         |
| `$wgMUARemoteWikiAPI`         | string | URL to the remote wiki's API. For FANDOM sites, this is something like `https://community.fandom.com/api.php`                                                                                                                         |         |
| `$wgMUAShowNoticeOnLogin`     | bool   | Whether to show a message directing users to migrate their accounts if necessary on Special:UserLogin and Special:CreateAccount *(if you prefer, set this to false and set your own message in `MediaWiki:Loginprompt` on your wiki)* | `true`  |
| `$wgMUALogToWiki`             | bool   | Whether we should log to a public log on `Special:Log` when a user migrates their account                                                                                                                                             | `true`  |
There are some additional config variables that you can find in `extension.json`, but they should generally not be used outside of [Weird Gloop](https://weirdgloop.org) wikis.

## Debugging
This extension logs to the channel `MigrateUserAccount`.

## License
This extension is licensed under GPLv3 - [see the license file](/LICENSE).

It is inspired by an extension called [UserMigration](https://gitlab.com/hydrawiki/extensions/usermigration/), which is no longer maintained. That project was maintained by foxlit, Alexia E. Smith (Curse Inc.), and others.
