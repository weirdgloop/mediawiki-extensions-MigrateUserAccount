/*!
 * JavaScript for Special:MigrateUserAccount.
 * Derived from resources/src/mediawiki.misc-authed-ooui/special.changecredentials.js
 */
( function () {
	mw.hook( 'htmlform.enhance' ).add( function ( $root ) {
		var api = new mw.Rest();

		$root.find( '.mw-migrateuseraccount-validate-password.oo-ui-fieldLayout' ).each( function () {
			var currentApiPromise,
				self = OO.ui.FieldLayout.static.infuse( $( this ) );

			self.getField().setValidation( function ( password ) {
				var d;

				if ( currentApiPromise ) {
					currentApiPromise.abort();
					currentApiPromise = undefined;
				}

				password = password.trim();

				if ( password === '' ) {
					self.setErrors( [] );
					return true;
				}

				d = $.Deferred();
				currentApiPromise = api.post( '/migrateuseraccount/v0/validatepassword', {
					username: $root.find( '#mw-input-wpusername' ).val(),
					password: password
				} ).done( function ( resp ) {
					var errors,
						good = resp.validity === 'Good' || !Object.keys(resp).length;

					currentApiPromise = undefined;

					if ( !good ) {
						errors = resp.validitymessages.map( function ( m ) {
							return new OO.ui.HtmlSnippet( m );
						} );
					}
					self.setErrors( errors || [] );
					d.resolve( good );
				} ).fail( d.reject );

				return d.promise( { abort: currentApiPromise.abort } );
			} );
		} );
	} );
}() );
