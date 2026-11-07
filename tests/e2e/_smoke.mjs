import { launch, context, adminLogin, openStudio, checker, sitesFromArgv } from './lib.mjs';
const browser = await launch();
let bad = 0;
for (const site of sitesFromArgv()) {
	const { ctx, issues } = await context(browser);
	const c = checker(`smoke ${site.key}`);
	const page = await adminLogin(ctx, site);
	await openStudio(page, site);
	c.ok((await page.locator('#uxs-root').innerText()).length > 100, 'module grid renders');
	bad += c.done(issues);
	await ctx.close();
}
await browser.close();
process.exit(bad ? 1 : 0);
