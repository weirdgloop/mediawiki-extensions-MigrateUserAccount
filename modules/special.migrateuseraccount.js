/*!
 * JavaScript for Special:MigrateUserAccount.
 * Derived from resources/src/mediawiki.misc-authed-ooui/special.changecredentials.js
 */
( function () {
	mw.hook( 'htmlform.enhance' ).add( ( $root ) => {
		const api = new mw.Rest();

		$root.find( '.mw-migrateuseraccount-validate-password.oo-ui-fieldLayout' ).each( function () {
			let currentApiPromise;
			const self = OO.ui.FieldLayout.static.infuse( $( this ) );

			self.getField().setValidation( ( password ) => {
				if ( currentApiPromise ) {
					currentApiPromise.abort();
					currentApiPromise = undefined;
				}

				password = password.trim();

				if ( password === '' ) {
					self.setErrors( [] );
					return true;
				}

				const d = $.Deferred();
				// eslint-disable-next-line no-jquery/no-done-fail
				currentApiPromise = api.post( '/migrateuseraccount/v0/validatepassword', {
					username: $root.find( '#mw-input-wpusername' ).val(),
					password: password
				} ).done( ( resp ) => {
					let errors;
					const good = resp.validity === 'Good' || !Object.keys( resp ).length;

					currentApiPromise = undefined;

					if ( !good ) {
						errors = resp.validitymessages.map( ( m ) => new OO.ui.HtmlSnippet( m ) );
					}
					self.setErrors( errors || [] );
					d.resolve( good );
				} ).fail( d.reject );

				return d.promise( { abort: currentApiPromise.abort } );
			} );
		} );
	} );
}() );
