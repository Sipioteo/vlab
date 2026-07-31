import { useId } from 'react';
import { Field, Select, Switch, TextArea, TextInput } from './ui';
import { Button } from './ui';
import { Icon } from './Icon';
import { t } from '@/i18n/it';
import type { Setting } from '@/types/api';

const WEEKDAYS = ['Domenica', 'Lunedì', 'Martedì', 'Mercoledì', 'Giovedì', 'Venerdì', 'Sabato'];

interface WeeklyEntry {
  weekday: number;
  closed: boolean;
  open: string | null;
  close: string | null;
}
interface WindowEntry {
  weekday: number;
  from: string;
  to: string;
}

function isWeekly(value: unknown): value is WeeklyEntry[] {
  return Array.isArray(value) && value.every((entry) => typeof entry === 'object' && entry !== null && 'closed' in entry);
}
function isWindows(value: unknown): value is WindowEntry[] {
  return Array.isArray(value) && value.every((entry) => typeof entry === 'object' && entry !== null && 'from' in entry);
}

/**
 * Renders the right control for a Setting purely from its `type`
 * (SPEC §11.5 `SettingField`). `null` means *infinite* for nullable numeric
 * settings, surfaced as an explicit "Illimitato" switch.
 */
export function SettingField({
  setting,
  value,
  onChange,
  disabled,
}: {
  setting: Setting;
  value: unknown;
  onChange: (value: unknown) => void;
  disabled?: boolean;
}) {
  const id = useId();
  const inputId = `set-${setting.key.replace(/\./g, '-')}-${id}`;
  const commonProps = { id: inputId, disabled };

  if (setting.type === 'bool') {
    return (
      <div className="vl-field">
        <Switch
          id={inputId}
          checked={value === true}
          onChange={onChange}
          disabled={disabled}
          label={setting.label_it}
        />
        {setting.description_it ? <span className="vl-field__hint">{setting.description_it}</span> : null}
      </div>
    );
  }

  if (setting.type === 'int') {
    const isInfinite = setting.nullable && value === null;
    return (
      <Field
        label={setting.label_it}
        htmlFor={inputId}
        hint={setting.description_it ?? undefined}
      >
        <div className="vl-stack" style={{ gap: 'var(--sp-2)' }}>
          <TextInput
            {...commonProps}
            type="number"
            value={isInfinite ? '' : String(value ?? '')}
            disabled={disabled || isInfinite}
            onChange={(e) => onChange(e.target.value === '' ? null : Number(e.target.value))}
          />
          {setting.nullable ? (
            <Switch
              checked={isInfinite}
              disabled={disabled}
              onChange={(checked) => onChange(checked ? null : 0)}
              label={t('staff.settingsInfinite')}
            />
          ) : null}
        </div>
      </Field>
    );
  }

  if (setting.type === 'enum') {
    return (
      <Field label={setting.label_it} htmlFor={inputId} hint={setting.description_it ?? undefined}>
        <Select {...commonProps} value={String(value ?? '')} onChange={(e) => onChange(e.target.value)}>
          {(setting.options ?? []).map((option) => (
            <option key={option} value={option}>
              {option}
            </option>
          ))}
        </Select>
      </Field>
    );
  }

  if (setting.type === 'time' || setting.type === 'date') {
    return (
      <Field label={setting.label_it} htmlFor={inputId} hint={setting.description_it ?? undefined}>
        <TextInput
          {...commonProps}
          type={setting.type}
          value={String(value ?? '')}
          onChange={(e) => onChange(e.target.value || null)}
        />
      </Field>
    );
  }

  if (setting.type === 'secret') {
    return (
      <Field
        label={setting.label_it}
        htmlFor={inputId}
        hint={t('staff.settingsSecretUnchanged')}
      >
        <TextInput
          {...commonProps}
          type="password"
          value={String(value ?? '')}
          onChange={(e) => onChange(e.target.value)}
        />
      </Field>
    );
  }

  if (setting.type === 'json') {
    if (setting.key === 'hours.weekly' && isWeekly(value)) {
      return (
        <fieldset style={{ border: 0, padding: 0, margin: 0 }}>
          <legend className="vl-field__label">{setting.label_it}</legend>
          <div className="vl-stack" style={{ gap: 'var(--sp-2)', marginTop: 'var(--sp-2)' }}>
            {value.map((entry, index) => (
              <div key={entry.weekday} className="vl-row" style={{ gap: 'var(--sp-3)' }}>
                <span style={{ minWidth: 96, fontSize: 'var(--fs-sm)' }}>
                  {WEEKDAYS[entry.weekday] ?? entry.weekday}
                </span>
                <Switch
                  checked={!entry.closed}
                  disabled={disabled}
                  label={entry.closed ? t('staff.weekdayClosed') : t('staff.weekdayOpen')}
                  onChange={(checked) => {
                    const next = [...value];
                    next[index] = checked
                      ? { ...entry, closed: false, open: entry.open ?? '09:00', close: entry.close ?? '17:00' }
                      : { ...entry, closed: true, open: null, close: null };
                    onChange(next);
                  }}
                />
                <label className="vl-sr-only" htmlFor={`${inputId}-open-${entry.weekday}`}>
                  {t('staff.weekdayOpen')}
                </label>
                <TextInput
                  id={`${inputId}-open-${entry.weekday}`}
                  type="time"
                  style={{ width: 120 }}
                  disabled={disabled || entry.closed}
                  value={entry.open ?? ''}
                  onChange={(e) => {
                    const next = [...value];
                    next[index] = { ...entry, open: e.target.value };
                    onChange(next);
                  }}
                />
                <label className="vl-sr-only" htmlFor={`${inputId}-close-${entry.weekday}`}>
                  {t('staff.weekdayClose')}
                </label>
                <TextInput
                  id={`${inputId}-close-${entry.weekday}`}
                  type="time"
                  style={{ width: 120 }}
                  disabled={disabled || entry.closed}
                  value={entry.close ?? ''}
                  onChange={(e) => {
                    const next = [...value];
                    next[index] = { ...entry, close: e.target.value };
                    onChange(next);
                  }}
                />
              </div>
            ))}
          </div>
        </fieldset>
      );
    }

    if ((setting.key === 'hours.pickup_windows' || setting.key === 'hours.return_windows') && isWindows(value)) {
      return (
        <fieldset style={{ border: 0, padding: 0, margin: 0 }}>
          <legend className="vl-field__label">{setting.label_it}</legend>
          <div className="vl-stack" style={{ gap: 'var(--sp-2)', marginTop: 'var(--sp-2)' }}>
            {value.map((entry, index) => (
              <div key={`${entry.weekday}-${index}`} className="vl-row" style={{ gap: 'var(--sp-2)' }}>
                <label className="vl-sr-only" htmlFor={`${inputId}-wd-${index}`}>
                  {WEEKDAYS[entry.weekday]}
                </label>
                <Select
                  id={`${inputId}-wd-${index}`}
                  disabled={disabled}
                  style={{ width: 'auto' }}
                  value={String(entry.weekday)}
                  onChange={(e) => {
                    const next = [...value];
                    next[index] = { ...entry, weekday: Number(e.target.value) };
                    onChange(next);
                  }}
                >
                  {WEEKDAYS.map((day, weekday) => (
                    <option key={day} value={weekday}>
                      {day}
                    </option>
                  ))}
                </Select>
                <TextInput
                  type="time"
                  style={{ width: 120 }}
                  disabled={disabled}
                  aria-label={t('app.from')}
                  value={entry.from}
                  onChange={(e) => {
                    const next = [...value];
                    next[index] = { ...entry, from: e.target.value };
                    onChange(next);
                  }}
                />
                <TextInput
                  type="time"
                  style={{ width: 120 }}
                  disabled={disabled}
                  aria-label={t('app.to')}
                  value={entry.to}
                  onChange={(e) => {
                    const next = [...value];
                    next[index] = { ...entry, to: e.target.value };
                    onChange(next);
                  }}
                />
                <Button
                  size="sm"
                  variant="quiet"
                  disabled={disabled}
                  aria-label={t('app.delete')}
                  onClick={() => onChange(value.filter((_, i) => i !== index))}
                >
                  <Icon name="trash" size={14} />
                </Button>
              </div>
            ))}
            <div>
              <Button
                size="sm"
                variant="ghost"
                disabled={disabled}
                onClick={() => onChange([...value, { weekday: 1, from: '09:00', to: '12:30' }])}
              >
                <Icon name="plus" size={14} />
                {t('staff.addWindow')}
              </Button>
            </div>
          </div>
        </fieldset>
      );
    }

    return (
      <Field label={setting.label_it} htmlFor={inputId} hint={setting.description_it ?? undefined}>
        <TextArea
          {...commonProps}
          style={{ fontFamily: 'var(--font-mono)', minHeight: 120 }}
          value={JSON.stringify(value, null, 2)}
          onChange={(e) => {
            try {
              onChange(JSON.parse(e.target.value));
            } catch {
              /* keep the previous parsed value until the JSON is valid again */
            }
          }}
        />
      </Field>
    );
  }

  return (
    <Field label={setting.label_it} htmlFor={inputId} hint={setting.description_it ?? undefined}>
      <TextInput
        {...commonProps}
        value={String(value ?? '')}
        onChange={(e) => onChange(e.target.value)}
      />
    </Field>
  );
}
