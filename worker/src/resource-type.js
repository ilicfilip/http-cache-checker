/**
 * Resource type constants and utilities.
 */

export const ResourceType = {
	HTML: 'html',
	JS: 'js',
	CSS: 'css',
	IMAGE: 'image',
	FONT: 'font',
	API: 'api',
	OTHER: 'other',

	/**
	 * Check if resource type is a static asset.
	 */
	isStatic(type) {
		return [this.JS, this.CSS, this.FONT, this.IMAGE].includes(type);
	},
};
