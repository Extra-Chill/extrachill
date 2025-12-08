<?php

class FileFinder {
	public static function find( string $directory, string $extension ): array {
		$files = [];
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $directory, RecursiveDirectoryIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( $file->isFile() && $file->getExtension() === $extension ) {
				$files[] = $file->getPathname();
			}
		}

		return $files;
	}
}
