const { join } = require('path');

/**
 * asPdf drives puppeteer's pinned Chrome-for-Testing rather than Debian's apt
 * chromium, which SIGTRAPs intermittently in containers. The download is cached
 * inside the checkout so it survives DDEV container rebuilds; the post-start
 * hook in .ddev/config.yaml populates it.
 */
module.exports = {
    cacheDirectory: join(__dirname, '.puppeteer-cache'),
};
