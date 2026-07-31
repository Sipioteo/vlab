import { Component, type ErrorInfo, type ReactNode } from 'react';
import { t } from '@/i18n/it';

interface Props {
  children: ReactNode;
}
interface State {
  error: Error | null;
}

export class ErrorBoundary extends Component<Props, State> {
  state: State = { error: null };

  static getDerivedStateFromError(error: Error): State {
    return { error };
  }

  componentDidCatch(error: Error, info: ErrorInfo): void {
    // eslint-disable-next-line no-console
    console.error('[vlab] render error', error, info.componentStack);
  }

  render() {
    if (this.state.error) {
      return (
        <div className="vl-container vl-page">
          <div className="vl-empty" role="alert">
            <h1>{t('errors.boundaryTitle')}</h1>
            <p>{t('errors.boundaryBody')}</p>
            <button
              type="button"
              className="vl-btn vl-btn--primary"
              onClick={() => window.location.reload()}
            >
              {t('errors.reload')}
            </button>
          </div>
        </div>
      );
    }
    return this.props.children;
  }
}
