<PanelBody title={ __( 'Adventure Settings', 'extrachill-content-blocks' ) }>
    <TextControl
        label={ __( 'Adventure Description', 'extrachill-content-blocks' ) }
        value={ attributes.adventurePrompt }
        onChange={ ( value ) => setAttributes( { adventurePrompt: value } ) }
        help={ __( 'Describe the overall plot in 2-3 sentences. This will be shown to the player before they start.', 'extrachill-content-blocks' ) }
        placeholder={ __( 'Adventure Description: Describe the overall plot in 2-3 sentences. This will be shown to the player before they start.', 'extrachill-content-blocks' ) }
    />
</PanelBody> 