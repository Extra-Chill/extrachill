/**
 * WordPress Entry Point for ContactForm
 *
 * Auto-mounts the ContactForm component when the page loads.
 * Reads configuration from window.ecContactConfig.
 */

/**
 * External dependencies
 */
import { createRoot } from 'react-dom/client';

/**
 * Internal dependencies
 */
import { ContactForm } from './ContactForm';
import type { ContactFormProps } from './types';

declare global {
  interface Window {
    ecContactConfig?: ContactFormProps;
  }
}

document.addEventListener('DOMContentLoaded', () => {
  const container = document.getElementById('ec-contact-form');
  
  if (!container) {
    return;
  }

  if (!window.ecContactConfig) {
    // eslint-disable-next-line no-console -- surface a missing config to site admins.
    console.error('ContactForm: ecContactConfig not found on window object');
    return;
  }

  const root = createRoot(container);
  root.render(<ContactForm {...window.ecContactConfig} />);
});
