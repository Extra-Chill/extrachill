import { useState, useEffect, useRef } from 'react';
import type { ContactFormProps, ContactFormData, ContactFormStatus } from './types';
import './ContactForm.css';

declare global {
  interface Window {
    turnstile?: {
      render: (container: HTMLElement, options: Record<string, unknown>) => string;
      reset: (widgetId?: string) => void;
      getResponse: (widgetId?: string) => string;
    };
  }
}

export function ContactForm({
  endpoint,
  restNonce,
  turnstileSiteKey,
  subjects,
  newsletterNotice,
  successMessage = "Thanks for reaching out! We'll be in touch soon.",
  successAction,
  onSuccess,
  onError,
  className,
}: ContactFormProps) {
  const [status, setStatus] = useState<ContactFormStatus>('idle');
  const [errorMessage, setErrorMessage] = useState<string>('');
  const [formData, setFormData] = useState<ContactFormData>({
    name: '',
    email: '',
    subject: '',
    message: '',
  });

  const turnstileRef = useRef<HTMLDivElement>(null);
  const turnstileWidgetId = useRef<string | null>(null);

  useEffect(() => {
    if (!turnstileSiteKey || !turnstileRef.current || !window.turnstile) {
      return;
    }

    if (turnstileWidgetId.current) {
      return;
    }

    turnstileWidgetId.current = window.turnstile.render(turnstileRef.current, {
      sitekey: turnstileSiteKey,
      theme: 'auto',
      appearance: 'interaction-only',
    });
  }, [turnstileSiteKey]);

  const resetTurnstile = () => {
    if (window.turnstile && turnstileWidgetId.current) {
      try {
        window.turnstile.reset(turnstileWidgetId.current);
      } catch {
        // Ignore reset errors
      }
    }
  };

  const getTurnstileResponse = (): string => {
    if (!turnstileSiteKey || !window.turnstile) {
      return '';
    }
    try {
      return window.turnstile.getResponse(turnstileWidgetId.current ?? undefined) || '';
    } catch {
      return '';
    }
  };

  const handleInputChange = (
    e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement>
  ) => {
    const { name, value } = e.target;
    setFormData((prev) => ({ ...prev, [name]: value }));
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();

    if (status === 'submitting') {
      return;
    }

    const turnstileResponse = getTurnstileResponse();
    if (turnstileSiteKey && !turnstileResponse) {
      setStatus('error');
      setErrorMessage('Please complete the security verification.');
      return;
    }

    setStatus('submitting');
    setErrorMessage('');

    try {
      const response = await fetch(endpoint, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          'X-WP-Nonce': restNonce,
        },
        body: JSON.stringify({
          name: formData.name,
          email: formData.email,
          subject: formData.subject,
          message: formData.message,
          turnstile_response: turnstileResponse,
        }),
      });

      const data = await response.json();

      if (!response.ok) {
        throw new Error(data.message || 'Something went wrong. Please try again.');
      }

      setStatus('success');
      onSuccess?.();
    } catch (error) {
      setStatus('error');
      const message = error instanceof Error ? error.message : 'Something went wrong. Please try again.';
      setErrorMessage(message);
      onError?.(error instanceof Error ? error : new Error(message));
      resetTurnstile();
    }
  };

  if (status === 'success') {
    return (
      <div className={`ec-contact-form ${className || ''}`}>
        <div className="ec-contact-form__feedback ec-contact-form__feedback--success">
          <p>{successMessage}</p>
          {successAction && (
            <p>
              <a href={successAction.url} className="ec-contact-form__action">
                {successAction.label}
              </a>
            </p>
          )}
        </div>
      </div>
    );
  }

  return (
    <div className={`ec-contact-form ${className || ''}`}>
      {status === 'error' && errorMessage && (
        <div className="ec-contact-form__feedback ec-contact-form__feedback--error">
          <p>{errorMessage}</p>
        </div>
      )}

      <form onSubmit={handleSubmit}>
        <div className="ec-contact-form__group">
          <label htmlFor="ec-contact-name" className="ec-contact-form__label">
            Name
          </label>
          <input
            type="text"
            id="ec-contact-name"
            name="name"
            className="ec-contact-form__input"
            value={formData.name}
            onChange={handleInputChange}
            required
            disabled={status === 'submitting'}
          />
        </div>

        <div className="ec-contact-form__group">
          <label htmlFor="ec-contact-email" className="ec-contact-form__label">
            Email
          </label>
          <input
            type="email"
            id="ec-contact-email"
            name="email"
            className="ec-contact-form__input"
            value={formData.email}
            onChange={handleInputChange}
            required
            disabled={status === 'submitting'}
          />
        </div>

        <div className="ec-contact-form__group">
          <label htmlFor="ec-contact-subject" className="ec-contact-form__label">
            Subject
          </label>
          <select
            id="ec-contact-subject"
            name="subject"
            className="ec-contact-form__select"
            value={formData.subject}
            onChange={handleInputChange}
            required
            disabled={status === 'submitting'}
          >
            <option value="">Select a subject</option>
            {subjects.map((subject) => (
              <option key={subject} value={subject}>
                {subject}
              </option>
            ))}
          </select>
        </div>

        <div className="ec-contact-form__group">
          <label htmlFor="ec-contact-message" className="ec-contact-form__label">
            Message
          </label>
          <textarea
            id="ec-contact-message"
            name="message"
            className="ec-contact-form__textarea"
            rows={5}
            value={formData.message}
            onChange={handleInputChange}
            required
            disabled={status === 'submitting'}
          />
        </div>

        {newsletterNotice && (
          <p className="ec-contact-form__notice">{newsletterNotice}</p>
        )}

        {turnstileSiteKey && (
          <div className="ec-contact-form__turnstile" ref={turnstileRef} />
        )}

        <div className="ec-contact-form__group">
          <button
            type="submit"
            className="ec-contact-form__submit"
            disabled={status === 'submitting'}
          >
            {status === 'submitting' ? 'Sending...' : 'Send Message'}
          </button>
        </div>
      </form>
    </div>
  );
}
