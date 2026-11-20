import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises } from '@vue/test-utils';

const config = {
  url: '/wp-admin/admin-ajax.php',
  action: 'sw_calendar_check_github_updates',
  nonce: 'test-nonce',
  due: false,
  messages: { checking: 'Checking', done: 'Done', failed: 'Failed' },
};
let request: ReturnType<typeof vi.fn>;
const button = () => document.querySelector<HTMLButtonElement>('button')!;
const result = () => document.querySelector<HTMLSpanElement>('span')!;
async function load(settings: object | undefined = config) {
  Reflect.set(window, 'sw_calendarVerifiedUpdates', settings);
  vi.resetModules();
  // @ts-expect-error -- Existing unbundled browser IIFE has no module type declarations.
  await import('../../../assets/js/sw-cloudcalendar-updates.js');
}
beforeEach(() => {
  vi.useFakeTimers();
  document.body.innerHTML =
    '<button id="sw_calendar-verified-updates-check">Check</button><span id="sw_calendar-verified-updates-result" hidden></span>';
  request = vi
    .fn()
    .mockResolvedValue({ ok: true, json: async () => ({ data: { message: '<b>Verified</b>' } }) });
  vi.stubGlobal('fetch', request);
});
afterEach(() => {
  vi.useRealTimers();
  vi.unstubAllGlobals();
  Reflect.deleteProperty(window, 'sw_calendarVerifiedUpdates');
  document.body.innerHTML = '';
});
describe('verified update check script', () => {
  it('waits for an explicit click and reports plain text without installing', async () => {
    await load();
    expect(request).not.toHaveBeenCalled();
    button().click();
    expect(button().disabled).toBe(true);
    expect(result().hidden).toBe(false);
    expect(result().textContent).toBe('Checking');
    await flushPromises();
    expect(result().textContent).toBe('<b>Verified</b>');
    expect(result().querySelector('b')).toBeNull();
    expect(button().disabled).toBe(false);
    expect(request).toHaveBeenCalledOnce();
    const args = request.mock.calls[0]![1] as RequestInit;
    expect(args.credentials).toBe('same-origin');
    expect(String(args.body)).toBe(
      'action=sw_calendar_check_github_updates&nonce=test-nonce&manual=1',
    );
  });
  it.each(['http', 'network', 'json', 'timeout'])(
    'reports %s errors and releases the button',
    async (failure) => {
      if (failure === 'http') request.mockResolvedValue({ ok: false });
      if (failure === 'network') request.mockRejectedValue(new Error('network'));
      if (failure === 'json')
        request.mockResolvedValue({
          ok: true,
          json: async () => {
            throw new Error('bad json');
          },
        });
      if (failure === 'timeout')
        request.mockImplementation(
          (_url, options: RequestInit) =>
            new Promise((_resolve, reject) =>
              options.signal?.addEventListener('abort', () => reject(new Error('aborted'))),
            ),
        );
      await load();
      button().click();
      if (failure === 'timeout') await vi.advanceTimersByTimeAsync(90000);
      await flushPromises();
      expect(result().textContent).toBe('Failed');
      expect(button().disabled).toBe(false);
    },
  );
  it('prevents concurrent clicks and uses fallback server text', async () => {
    let finish!: (response: object) => void;
    request.mockImplementation(
      () =>
        new Promise((resolve) => {
          finish = resolve;
        }),
    );
    await load();
    button().click();
    button().disabled = false;
    button().click();
    expect(request).toHaveBeenCalledOnce();
    finish({ ok: true, json: async () => ({ success: true }) });
    await flushPromises();
    expect(result().textContent).toBe('Done');
  });
  it.each([true, false])('defers due checks using idle callback %s', async (idle) => {
    if (idle)
      vi.stubGlobal('requestIdleCallback', (callback: () => void) =>
        window.setTimeout(callback, 20),
      );
    await load({ ...config, due: true });
    expect(request).not.toHaveBeenCalled();
    await vi.advanceTimersByTimeAsync(1500);
    await flushPromises();
    expect(request).toHaveBeenCalledOnce();
    expect(String(request.mock.calls[0]![1].body)).toContain('manual=0');
    expect(result().hidden).toBe(true);
  });
  it('supports other admin screens without controls and missing configuration', async () => {
    document.body.innerHTML = '';
    await load({ ...config, due: true });
    await vi.advanceTimersByTimeAsync(1500);
    await flushPromises();
    expect(request).toHaveBeenCalledOnce();
    request.mockClear();
    await load(undefined);
    expect(request).not.toHaveBeenCalled();
  });
});
