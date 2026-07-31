import { useRef, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import * as api from '@/api/endpoints';
import { useToast } from '@/components/Toast';
import { Button, Card, ConfirmDialog, Field, Skeleton, TextInput } from '@/components/ui';
import { t } from '@/i18n/it';

/**
 * "Calendario (iCal)" block: the private subscription URL, a copy button and a
 * rotate button behind a confirmation — the old link dies the moment you
 * regenerate, and people paste these into three devices, so we say so first.
 */
export function IcalFeedCard({ staff = false }: { staff?: boolean }) {
  const queryClient = useQueryClient();
  const { push, pushError } = useToast();
  const [confirmOpen, setConfirmOpen] = useState(false);
  const inputRef = useRef<HTMLInputElement>(null);

  const feed = useQuery({ queryKey: ['ical-feed'], queryFn: api.getIcalFeed });

  const rotate = useMutation({
    mutationFn: api.rotateIcalFeed,
    onSuccess: (data) => {
      queryClient.setQueryData(['ical-feed'], data);
      setConfirmOpen(false);
      push(t('ical.rotated'), 'success');
    },
    onError: (error) => {
      setConfirmOpen(false);
      pushError(error);
    },
  });

  const url = feed.data?.feed_url ?? '';

  const copy = async () => {
    // Select first: on a clipboard-less browser the user still ends up with the
    // whole URL highlighted and one Ctrl+C away.
    inputRef.current?.select();
    try {
      await navigator.clipboard.writeText(url);
      push(t('ical.copied'), 'success');
    } catch {
      push(t('ical.copyFailed'), 'info');
    }
  };

  return (
    <Card title={t('ical.title')} headingLevel={2}>
      <p className="vl-subtle" style={{ marginTop: 0 }}>
        {staff ? t('ical.leadStaff') : t('ical.leadStudent')}
      </p>

      {feed.isLoading ? (
        <Skeleton height={72} radius={6} />
      ) : (
        <>
          <Field label={t('ical.fieldLabel')} htmlFor="ical-feed-url" hint={t('ical.privacyNote')}>
            <div className="vl-icalrow">
              <TextInput
                id="ical-feed-url"
                ref={inputRef}
                value={url}
                readOnly
                onFocus={(e) => e.currentTarget.select()}
                spellCheck={false}
              />
              <Button variant="secondary" icon="clipboard" onClick={() => void copy()}>
                {t('ical.copy')}
              </Button>
            </div>
          </Field>

          <div className="vl-row" style={{ marginTop: 'var(--sp-4)' }}>
            <Button
              variant="ghost"
              icon="refresh"
              onClick={() => setConfirmOpen(true)}
              loading={rotate.isPending}
            >
              {t('ical.rotate')}
            </Button>
          </div>

          <ul className="vl-ical-howto">
            <li>{t('ical.howToGoogle')}</li>
            <li>{t('ical.howToApple')}</li>
            <li>{t('ical.howToOutlook')}</li>
          </ul>
        </>
      )}

      <ConfirmDialog
        open={confirmOpen}
        title={t('ical.rotateConfirmTitle')}
        body={t('ical.rotateConfirmBody')}
        confirmLabel={t('ical.rotate')}
        danger
        loading={rotate.isPending}
        onConfirm={() => rotate.mutate()}
        onCancel={() => setConfirmOpen(false)}
      />
    </Card>
  );
}
