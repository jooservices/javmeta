import { defineStep } from '../lib/step.mjs';

export const fillNodeName = defineStep('fillNodeName', async (ctx, name) => { await ctx.page.fill('#node-name', name); });

export const submitNodeForm = defineStep('submitNodeForm', async ctx => { await ctx.page.click('#node-create'); });

export const expectBrowserRejectsNodeName = defineStep('expectBrowserRejectsNodeName', async ctx => {
  if (await ctx.page.$eval('#node-name', e => e.validity.valid)) throw new Error('The browser accepted an invalid node name.');
});

export const expectJoinCommand = defineStep('expectJoinCommand', async (ctx, name) => {
  await ctx.page.waitForSelector('#join-box:not([hidden])', { timeout: 8000 });
  const command = await ctx.page.$eval('#join-command', e => e.textContent);
  const shape = new RegExp(`^curl -fsSL https?://\\S+/node/join\\.sh \\| sudo bash -s -- --token jne_[a-f0-9]{64} --name ${name}$`);
  if (!shape.test(command)) throw new Error('The join command does not have the expected shape.');
});

export const copyJoinCommand = defineStep('copyJoinCommand', async ctx => {
  await ctx.page.click('#join-copy');
  await ctx.page.waitForFunction(() => /Copied|Ctrl\+C/.test(document.querySelector('#join-copied')?.textContent || ''), null, { timeout: 4000 });
});

export const expectNodeListed = defineStep('expectNodeListed', async (ctx, name, status) => {
  await ctx.page.waitForFunction(([n, s]) => [...document.querySelectorAll('.node-row')].some(r => r.innerText.includes(n) && r.innerText.includes(s)), [name, status], { timeout: 8000 });
});
