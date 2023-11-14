<?php

class MyCustomServer extends Wpup_UpdateServer {
	public function handleRequest( $query = null, $headers = null ) {

		$this->startTime = microtime( true );
		$request         = $this->initRequest( $query, $headers );

		$this->loadPackageFor( $request );
		$this->validateRequest( $request );
		$this->checkAuthorization( $request );
		$this->dispatch( $request );
		exit;
	}

	protected function generateDownloadUrl( Wpup_Package $package ) {
		$query    = array(
			'action' => 'download',
			'slug'   => $package->slug,
		);
		$metadata = $package->getMetadata();

		return self::addQueryArg( $query, $this->serverUrl ) . '&ver=' . $metadata['version'];
	}


	protected function actionDownload( Wpup_Request $request ) {
		$package   = $request->package;
		$cacheTime = 3 * 3600; // Set cache headers for 6 hours (3 hours * 3600 seconds)

		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . $package->slug . '.zip"' );
		header( 'Content-Transfer-Encoding: binary' );
		header( 'Content-Length: ' . $package->getFileSize() );
		header( 'Cache-Control: public, max-age=' . $cacheTime );

		readfile( $package->getFilename() );
	}

}
