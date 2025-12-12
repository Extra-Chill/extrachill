export interface ContactFormProps {
  endpoint: string;
  restNonce: string;
  turnstileSiteKey?: string;
  subjects: string[];
  newsletterNotice?: string;
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
  turnstile_response?: string;
}

export type ContactFormStatus = 'idle' | 'submitting' | 'success' | 'error';
