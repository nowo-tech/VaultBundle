import { expect, type Page } from '@playwright/test';

/** The Symfony WebProfiler toolbar is not part of the demo platform: hide it so it never appears in a capture. */
export async function waitForToolbar(page: Page): Promise<void> {
  await page.addStyleTag({ content: '.sf-toolbar,.sf-minitoolbar,[id^="sfwdt"]{display:none!important}' });
  await page.waitForTimeout(300);
}
