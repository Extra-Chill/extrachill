export interface ContactFormProps {
  endpoint: string;
  restNonce: string;
  turnstileSiteKey?: string;
  subjects: string[];
  /** Subject preselected when the form loads (must be one of `subjects`). */
  initialSubject?: string;
  /** Label for the unchecked newsletter opt-in box. Omit to hide the box. */
  newsletterOptIn?: string;
  successMessage?: string;
  successAction?: {
    label: string;
    url: string;
  };
  onSuccess?: () => void;
  onError?: (error: Error) => void;
  className?: string;
}

export interface ContactFormData {
  name: string;
  email: string;
  subject: string;
  message: string;
  newsletter_opt_in: boolean;
  turnstile_response?: string;
}

export type ContactFormStatus = 'idle' | 'submitting' | 'success' | 'error';
