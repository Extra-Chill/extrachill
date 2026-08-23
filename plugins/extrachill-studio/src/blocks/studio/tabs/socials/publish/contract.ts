import type { SocialPlatformConfig } from '@extrachill/api-client';

export interface ComposerSchemaProperty {
	type?: 'string' | 'boolean' | 'integer' | 'number' | 'array';
	description?: string;
	default?: unknown;
	enum?: string[];
	format?: string;
	maxLength?: number;
	items?: { type?: string };
}

export interface ComposerInputSchema {
	type?: string;
	required?: string[];
	oneOf?: Array< { required?: string[] } >;
	properties?: Record< string, ComposerSchemaProperty >;
}

export interface ComposerContract {
	crossPostCompatible: boolean;
	mediaKinds: string[];
	target: {
		transport: 'rest' | 'ability';
		name: string;
	};
	inputSchema: ComposerInputSchema;
	mediaRequirements: Record<
		string,
		{
			required?: string[];
			requiredAnyOf?: string[];
		}
	>;
}

export interface ComposerPlatformConfig extends SocialPlatformConfig {
	composer: ComposerContract | null;
	preview?: {
		aspectRatio?: string;
		captionPosition?: 'above' | 'below' | 'overlay';
		previewSurface?: string;
	};
}

export interface ComposerRequest {
	path: string;
	method: 'POST';
	data: Record< string, unknown >;
}

export interface PublishOutcome {
	success?: boolean;
	status?: string;
	id?: string;
	url?: string;
	privacy?: string;
}

export const filterAvailablePlatforms = (
	platforms: ComposerPlatformConfig[],
	allowedSlugs: string[]
): ComposerPlatformConfig[] =>
	platforms.filter(
		( platform ) =>
			platform.authenticated &&
			( allowedSlugs.length === 0 ||
				allowedSlugs.includes( platform.slug ) )
	);

export const buildComposerRequest = (
	contract: ComposerContract,
	input: Record< string, unknown >
): ComposerRequest => {
	if (
		contract.crossPostCompatible &&
		contract.target.transport === 'rest'
	) {
		return {
			path: `/${ contract.target.name }`,
			method: 'POST',
			data: input,
		};
	}

	if (
		! contract.crossPostCompatible &&
		contract.target.transport === 'ability'
	) {
		return {
			path: `/wp-abilities/v1/abilities/${ contract.target.name }/run`,
			method: 'POST',
			data: { input },
		};
	}

	throw new Error(
		'This publisher does not declare a supported composer transport.'
	);
};

export const schemaDefaults = (
	schema: ComposerInputSchema
): Record< string, unknown > =>
	Object.fromEntries(
		Object.entries( schema.properties || {} )
			.filter( ( [ , property ] ) => property.default !== undefined )
			.map( ( [ name, property ] ) => [ name, property.default ] )
	);

const isBrowserField = (
	name: string,
	property?: ComposerSchemaProperty
): boolean =>
	! name.endsWith( '_file_path' ) &&
	! /absolute local path/i.test( property?.description || '' );

export const browserComposerSchema = (
	schema: ComposerInputSchema
): ComposerInputSchema => {
	if ( ! schema.oneOf?.length ) {
		return schema;
	}

	const properties = schema.properties || {};
	const selected = schema.oneOf.find( ( option ) =>
		( option.required || [] ).every( ( name ) =>
			isBrowserField( name, properties[ name ] )
		)
	);
	if ( ! selected ) {
		return schema;
	}

	const alternateFields = schema.oneOf.flatMap(
		( option ) => option.required || []
	);
	return {
		...schema,
		required: [
			...new Set( [
				...( schema.required || [] ),
				...( selected.required || [] ),
			] ),
		],
		oneOf: undefined,
		properties: Object.fromEntries(
			Object.entries( properties ).filter(
				( [ name, property ] ) =>
					! alternateFields.includes( name ) ||
					( selected.required || [] ).includes( name ) ||
					isBrowserField( name, property )
			)
		),
	};
};

const isUri = ( value: string ): boolean => {
	try {
		const url = new URL( value );
		return url.protocol === 'https:' || url.protocol === 'http:';
	} catch {
		return false;
	}
};

export const normalizePublishOutcome = (
	result: Record< string, unknown >
): PublishOutcome => {
	const firstString = ( names: string[] ): string | undefined => {
		for ( const name of names ) {
			if ( typeof result[ name ] === 'string' && result[ name ] ) {
				return result[ name ] as string;
			}
		}
		return undefined;
	};

	const url = firstString( [
		'platform_url',
		'post_url',
		'url',
		'permalink',
	] );

	return {
		success:
			typeof result.success === 'boolean' ? result.success : undefined,
		status: firstString( [ 'status' ] ),
		id: firstString( [
			'platform_post_id',
			'video_id',
			'public_post_id',
			'publish_id',
			'media_id',
			'post_id',
		] ),
		url: url && isUri( url ) ? url : undefined,
		privacy: firstString( [ 'privacy_status', 'privacy_level' ] ),
	};
};

const hasValue = ( value: unknown ): boolean =>
	value !== undefined && value !== null && value !== '';

export const validateComposerInput = (
	schema: ComposerInputSchema,
	input: Record< string, unknown >
): string[] => {
	const errors: string[] = [];
	for ( const name of schema.required || [] ) {
		if ( ! hasValue( input[ name ] ) ) {
			errors.push( `${ name.replace( /_/g, ' ' ) }: is required` );
		}
	}

	if ( schema.oneOf ) {
		const matchingOptions = schema.oneOf.filter( ( option ) =>
			( option.required || [] ).every( ( name ) =>
				hasValue( input[ name ] )
			)
		);
		if ( matchingOptions.length !== 1 ) {
			const choices = schema.oneOf
				.flatMap( ( option ) => option.required || [] )
				.map( ( name ) => name.replace( /_/g, ' ' ) )
				.join( ' or ' );
			errors.push( `input: provide exactly one of ${ choices }` );
		}
	}

	for ( const [ name, property ] of Object.entries(
		schema.properties || {}
	) ) {
		const value = input[ name ];
		if ( ! hasValue( value ) ) {
			continue;
		}

		const typeMatches =
			! property.type ||
			( property.type === 'string' && typeof value === 'string' ) ||
			( property.type === 'boolean' && typeof value === 'boolean' ) ||
			( property.type === 'number' && typeof value === 'number' ) ||
			( property.type === 'integer' && Number.isInteger( value ) ) ||
			( property.type === 'array' && Array.isArray( value ) );
		if ( ! typeMatches ) {
			errors.push( `${ fieldLabel( name ) }: has an invalid value type` );
			continue;
		}
		if ( property.enum && ! property.enum.includes( String( value ) ) ) {
			errors.push( `${ fieldLabel( name ) }: choose a supported value` );
		}
		if (
			property.maxLength &&
			String( value ).length > property.maxLength
		) {
			errors.push(
				`${ fieldLabel( name ) }: exceeds ${
					property.maxLength
				} characters`
			);
		}
		if ( property.format === 'uri' && ! isUri( String( value ) ) ) {
			errors.push(
				`${ fieldLabel( name ) }: enter a valid HTTP or HTTPS URL`
			);
		}
	}

	return errors;
};

const fieldLabel = ( name: string ): string => name.replace( /_/g, ' ' );
