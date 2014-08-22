track-custom-keywords
=====================

YOURLS plugin to add a new field to YOURLS designed to track
if a keyword was randomly assigned or manually specified.

- New keywords are appended with a prefix to indicate as to whether they were random or custom
- This prefix is stripped off and stored in a new field that is added to the database when the plugin is activated
- The edit functionality will allow you to override the flag
- The link list now has an additional sortable column indicating if a keyword is custom or not
- The search bar has an option to only show custom or random keywords

When this plugin is activated, a new column is added to your YOURLS_URL table to hold the flag. If you deactivate the plugin, this column is removed. **Note that your data is lost if you do this.**

See the admin.png file in the repository for an example of the changes to the web UI.
