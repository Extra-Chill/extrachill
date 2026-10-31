/**
 * WordPress Entry Point for ContactForm
 *
 * Auto-mounts the ContactForm component when the page loads.
 * Reads configuration from window.ecContactConfig.
 */

import { createRoot } from 'react-dom/client';
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
    console.error('ContactForm: ecContactConfig not found on window object');
    return;
  }

  const root = createRoot(container);
  root.render(<ContactForm {...window.ecContactConfig} />);
});
