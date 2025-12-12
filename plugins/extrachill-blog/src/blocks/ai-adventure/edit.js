<PanelBody title={ __( 'Adventure Settings', 'extrachill-blog' ) }>
    <TextControl
        label={ __( 'Adventure Description', 'extrachill-blog' ) }
        value={ attributes.adventurePrompt }
        onChange={ ( value ) => setAttributes( { adventurePrompt: value } ) }
        help={ __( 'Describe the overall plot in 2-3 sentences. This will be shown to the player before they start.', 'extrachill-blog' ) }
        placeholder={ __( 'Adventure Description: Describe the overall plot in 2-3 sentences. This will be shown to the player before they start.', 'extrachill-blog' ) }
    />
</PanelBody> 