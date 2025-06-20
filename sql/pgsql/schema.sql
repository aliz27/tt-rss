drop table if exists ttrss_scheduled_tasks;
drop table if exists ttrss_error_log;
drop table if exists ttrss_plugin_storage;
drop table if exists ttrss_linked_feeds;
drop table if exists ttrss_linked_instances;
drop table if exists ttrss_access_keys;
drop table if exists ttrss_user_labels2;
drop table if exists ttrss_labels2;
drop table if exists ttrss_feedbrowser_cache;
drop table if exists ttrss_labels;
drop table if exists ttrss_filters2_rules;
drop table if exists ttrss_filters2_actions;
drop table if exists ttrss_filters2;
drop table if exists ttrss_filters;
drop table if exists ttrss_filter_types;
drop table if exists ttrss_filter_actions;
drop table if exists ttrss_user_prefs;
drop table if exists ttrss_user_prefs2;
drop table if exists ttrss_prefs;
drop table if exists ttrss_prefs_types;
drop table if exists ttrss_prefs_sections;
drop table if exists ttrss_tags;
drop table if exists ttrss_enclosures;
drop table if exists ttrss_settings_profiles;
drop table if exists ttrss_entry_comments;
drop table if exists ttrss_user_entries;
drop table if exists ttrss_entries;
drop table if exists ttrss_scheduled_updates;
drop table if exists ttrss_counters_cache;
drop table if exists ttrss_cat_counters_cache;
drop table if exists ttrss_archived_feeds;
drop table if exists ttrss_feeds;
drop table if exists ttrss_feed_categories;
drop table if exists ttrss_app_passwords;
drop table if exists ttrss_users;
drop table if exists ttrss_themes;
drop table if exists ttrss_sessions;
drop function if exists SUBSTRING_FOR_DATE(timestamp, int, int);

begin;

create table ttrss_users (id serial not null primary key,
	login varchar(120) not null unique,
	pwd_hash varchar(250) not null,
	last_login timestamp default null,
	access_level integer not null default 0,
	email varchar(250) not null default '',
	full_name varchar(250) not null default '',
	email_digest boolean not null default false,
	last_digest_sent timestamp default null,
	salt varchar(250) not null default '',
	twitter_oauth text default null,
	otp_enabled boolean not null default false,
	otp_secret varchar(250) default null,
	resetpass_token varchar(250) default null,
	last_auth_attempt timestamp default null,
	created timestamp default null);

insert into ttrss_users (login,pwd_hash,access_level) values ('admin',
	'SHA1:5baa61e4c9b93f3f0682250b6cf8331b7ee68fd8', 10);

create table ttrss_app_passwords (id serial not null primary key,
    title varchar(250) not null,
    pwd_hash text not null,
    service varchar(100) not null,
    created timestamp not null,
    last_used timestamp default null,
    owner_uid integer not null references ttrss_users(id) on delete cascade);

create table ttrss_feed_categories(id serial not null primary key,
	owner_uid integer not null references ttrss_users(id) on delete cascade,
	collapsed boolean not null default false,
	order_id integer not null default 0,
	view_settings varchar(250) not null default '',
	parent_cat integer references ttrss_feed_categories(id) on delete set null,
	title varchar(200) not null);

create table ttrss_feeds (id serial not null primary key,
	owner_uid integer not null references ttrss_users(id) on delete cascade,
	title varchar(200) not null,
	cat_id integer default null references ttrss_feed_categories(id) on delete set null,
	feed_url text not null,
	icon_url varchar(250) not null default '',
	update_interval integer not null default 0,
	purge_interval integer not null default 0,
	last_updated timestamp default null,
	last_unconditional timestamp default null,
	last_error text not null default '',
	last_modified text not null default '',
	favicon_avg_color varchar(11) default null,
	favicon_is_custom boolean default null,
	site_url varchar(250) not null default '',
	auth_login varchar(250) not null default '',
	parent_feed integer default null references ttrss_feeds(id) on delete set null,
	private boolean not null default false,
	auth_pass text not null default '',
	hidden boolean not null default false,
	include_in_digest boolean not null default true,
	rtl_content boolean not null default false,
	cache_images boolean not null default false,
	hide_images boolean not null default false,
	cache_content boolean not null default false,
	last_viewed timestamp default null,
	last_update_started timestamp default null,
	last_successful_update timestamp default null,
	update_method integer not null default 0,
	always_display_enclosures boolean not null default false,
	order_id integer not null default 0,
	mark_unread_on_update boolean not null default false,
	update_on_checksum_change boolean not null default false,
	strip_images boolean not null default false,
	view_settings varchar(250) not null default '',
	pubsub_state integer not null default 0,
	favicon_last_checked timestamp default null,
	feed_language varchar(100) not null default '',
	auth_pass_encrypted boolean not null default false,
	unique(feed_url, owner_uid));

create index ttrss_feeds_owner_uid_index on ttrss_feeds(owner_uid);
create index ttrss_feeds_cat_id_idx on ttrss_feeds(cat_id);

create table ttrss_archived_feeds (id integer not null primary key,
	owner_uid integer not null references ttrss_users(id) on delete cascade,
	created timestamp not null,
	title varchar(200) not null,
	feed_url text not null,
	site_url varchar(250) not null default '');

create table ttrss_counters_cache (
	feed_id integer not null,
	owner_uid integer not null references ttrss_users(id) ON DELETE CASCADE,
	updated timestamp not null,
	value integer not null default 0);

create index ttrss_counters_cache_feed_id_idx on ttrss_counters_cache(feed_id);
create index ttrss_counters_cache_owner_uid_idx on ttrss_counters_cache(owner_uid);
create index ttrss_counters_cache_value_idx on ttrss_counters_cache(value);

create table ttrss_cat_counters_cache (
	feed_id integer not null,
	owner_uid integer not null references ttrss_users(id) ON DELETE CASCADE,
	updated timestamp not null,
	value integer not null default 0);

create index ttrss_cat_counters_cache_owner_uid_idx on ttrss_cat_counters_cache(owner_uid);

create table ttrss_entries (id serial not null primary key,
	title text not null,
	guid text not null unique,
	link text not null,
	updated timestamp not null,
	content text not null,
	content_hash varchar(250) not null,
	cached_content text,
	no_orig_date boolean not null default false,
	date_entered timestamp not null,
	date_updated timestamp not null,
	num_comments integer not null default 0,
	comments varchar(250) not null default '',
	plugin_data text,
	tsvector_combined tsvector,
	lang varchar(2),
	author varchar(250) not null default '');

-- create index ttrss_entries_title_index on ttrss_entries(title);
create index ttrss_entries_date_entered_index on ttrss_entries(date_entered);
create index ttrss_entries_updated_idx on ttrss_entries(updated);
create index ttrss_entries_tsvector_combined_idx on ttrss_entries using gin(tsvector_combined);

create table ttrss_user_entries (
	int_id serial not null primary key,
	ref_id integer not null references ttrss_entries(id) ON DELETE CASCADE,
	uuid varchar(200) not null,
	feed_id int references ttrss_feeds(id) ON DELETE CASCADE,
	orig_feed_id integer references ttrss_archived_feeds(id) ON DELETE SET NULL,
	owner_uid integer not null references ttrss_users(id) ON DELETE CASCADE,
	marked boolean not null default false,
	published boolean not null default false,
	tag_cache text not null,
	label_cache text not null,
	last_read timestamp,
	score int not null default 0,
	last_marked timestamp,
	last_published timestamp,
	note text,
	unread boolean not null default true);

-- create index ttrss_user_entries_feed_id_index on ttrss_user_entries(feed_id);
create index ttrss_user_entries_owner_uid_index on ttrss_user_entries(owner_uid);
create index ttrss_user_entries_ref_id_index on ttrss_user_entries(ref_id);
create index ttrss_user_entries_feed_id on ttrss_user_entries(feed_id);
create index ttrss_user_entries_unread_idx on ttrss_user_entries(unread);

create table ttrss_entry_comments (id serial not null primary key,
	ref_id integer not null references ttrss_entries(id) ON DELETE CASCADE,
	owner_uid integer not null references ttrss_users(id) ON DELETE CASCADE,
	private boolean not null default false,
	date_entered timestamp not null);

create index ttrss_entry_comments_ref_id_index on ttrss_entry_comments(ref_id);
-- create index ttrss_entry_comments_owner_uid_index on ttrss_entry_comments(owner_uid);

create table ttrss_filter_types (id integer not null primary key,
	name varchar(120) unique not null,
	description varchar(250) not null unique);

insert into ttrss_filter_types (id,name,description) values (1, 'title', 'Title');
insert into ttrss_filter_types (id,name,description) values (2, 'content', 'Content');
insert into ttrss_filter_types (id,name,description) values (3, 'both',
	'Title or Content');
insert into ttrss_filter_types (id,name,description) values (4, 'link',
	'Link');
insert into ttrss_filter_types (id,name,description) values (5, 'date',
	'Article Date');
insert into ttrss_filter_types (id,name,description) values (6, 'author', 'Author');
insert into ttrss_filter_types (id,name,description) values (7, 'tag', 'Article Tags');

create table ttrss_filter_actions (id integer not null primary key,
	name varchar(120) unique not null,
	description varchar(250) not null unique);

insert into ttrss_filter_actions (id,name,description) values (1, 'filter',
	'Delete article');

insert into ttrss_filter_actions (id,name,description) values (2, 'catchup',
	'Mark as read');

insert into ttrss_filter_actions (id,name,description) values (3, 'mark',
	'Set starred');

insert into ttrss_filter_actions (id,name,description) values (4, 'tag',
	'Assign tags');

insert into ttrss_filter_actions (id,name,description) values (5, 'publish',
	'Publish article');

insert into ttrss_filter_actions (id,name,description) values (6, 'score',
	'Modify score');

insert into ttrss_filter_actions (id,name,description) values (7, 'label',
	'Assign label');

insert into ttrss_filter_actions (id,name,description) values (8, 'stop',
	'Stop / Do nothing');

insert into ttrss_filter_actions (id,name,description) values (9, 'plugin',
	'Invoke plugin');

insert into ttrss_filter_actions (id,name,description) values (10, 'ignore-tag',
	'Ignore tags');

create table ttrss_filters2(id serial not null primary key,
	owner_uid integer not null references ttrss_users(id) on delete cascade,
	match_any_rule boolean not null default false,
	inverse boolean not null default false,
	title varchar(250) not null default '',
	order_id integer not null default 0,
	last_triggered timestamp default null,
	enabled boolean not null default true);

create table ttrss_filters2_rules(id serial not null primary key,
	filter_id integer not null references ttrss_filters2(id) on delete cascade,
	reg_exp text not null,
	inverse boolean not null default false,
	filter_type integer not null references ttrss_filter_types(id),
	feed_id integer references ttrss_feeds(id) on delete cascade default null,
	cat_id integer references ttrss_feed_categories(id) on delete cascade default null,
	match_on text,
	cat_filter boolean not null default false);

create table ttrss_filters2_actions(id serial not null primary key,
	filter_id integer not null references ttrss_filters2(id) on delete cascade,
	action_id integer not null default 1 references ttrss_filter_actions(id) on delete cascade,
	action_param varchar(250) not null default '');

create table ttrss_tags (id serial not null primary key,
	tag_name varchar(250) not null,
	owner_uid integer not null references ttrss_users(id) on delete cascade,
	post_int_id integer references ttrss_user_entries(int_id) ON DELETE CASCADE not null);

create index ttrss_tags_owner_uid_index on ttrss_tags(owner_uid);
create index ttrss_tags_post_int_id_idx on ttrss_tags(post_int_id);

create table ttrss_enclosures (id serial not null primary key,
	content_url text not null,
	content_type varchar(250) not null,
	title text not null,
	duration text not null,
	width integer not null default 0,
	height integer not null default 0,
	post_id integer references ttrss_entries(id) ON DELETE cascade NOT NULL);

create index ttrss_enclosures_post_id_idx on ttrss_enclosures(post_id);

create table ttrss_settings_profiles(id serial not null primary key,
	title varchar(250) not null,
	owner_uid integer not null references ttrss_users(id) on delete cascade);

create table ttrss_prefs_types (id integer not null primary key,
	type_name varchar(100) not null);

create table ttrss_prefs_sections (id integer not null primary key,
	order_id integer not null,
	section_name varchar(100) not null);

create table ttrss_prefs (pref_name varchar(250) not null primary key,
	type_id integer not null references ttrss_prefs_types(id),
	section_id integer not null default 1 references ttrss_prefs_sections(id),
	access_level integer not null default 0,
	def_value text not null);

create table ttrss_user_prefs (
	owner_uid integer not null references ttrss_users(id) ON DELETE CASCADE,
	pref_name varchar(250) not null references ttrss_prefs(pref_name) ON DELETE CASCADE,
	profile integer references ttrss_settings_profiles(id) ON DELETE CASCADE,
	value text not null);

create index ttrss_user_prefs_owner_uid_index on ttrss_user_prefs(owner_uid);
create index ttrss_user_prefs_pref_name_idx on ttrss_user_prefs(pref_name);

create table ttrss_user_prefs2 (
	owner_uid integer not null references ttrss_users(id) ON DELETE CASCADE,
	pref_name varchar(250) not null,
	profile integer references ttrss_settings_profiles(id) ON DELETE CASCADE,
	value text not null);

create index ttrss_user_prefs2_owner_uid_index on ttrss_user_prefs2(owner_uid);
create index ttrss_user_prefs2_pref_name_idx on ttrss_user_prefs2(pref_name);
create unique index ttrss_user_prefs2_composite_idx on ttrss_user_prefs2(pref_name, owner_uid, coalesce(profile, -1));

create table ttrss_sessions (id varchar(250) not null primary key,
	data text,
	expire integer not null);

create index ttrss_sessions_expire_index on ttrss_sessions(expire);

create function SUBSTRING_FOR_DATE(timestamp, int, int) RETURNS text AS 'SELECT SUBSTRING(CAST($1 AS text), $2, $3)' LANGUAGE 'sql';

create table ttrss_feedbrowser_cache (
	feed_url text not null primary key,
	title text not null,
	site_url text not null,
	subscribers integer not null);

create table ttrss_labels2 (id serial not null primary key,
	owner_uid integer not null references ttrss_users(id) ON DELETE CASCADE,
	fg_color varchar(15) not null default '',
	bg_color varchar(15) not null default '',
	caption varchar(250) not null
);

create table ttrss_user_labels2 (
	label_id integer not null references ttrss_labels2(id) ON DELETE CASCADE,
	article_id integer not null references ttrss_entries(id) ON DELETE CASCADE
);

create index ttrss_user_labels2_article_id_idx on ttrss_user_labels2(article_id);
create index ttrss_user_labels2_label_id_idx on ttrss_user_labels2(label_id);

create table ttrss_access_keys (id serial not null primary key,
	access_key varchar(250) not null,
	feed_id varchar(250) not null,
	is_cat boolean not null default false,
	owner_uid integer not null references ttrss_users(id) on delete cascade);

create table ttrss_linked_instances (id serial not null primary key,
	last_connected timestamp not null,
	last_status_in integer not null,
	last_status_out integer not null,
	access_key varchar(250) not null unique,
	access_url text not null);

create table ttrss_linked_feeds (
	feed_url text not null,
	site_url text not null,
	title text not null,
	created timestamp not null,
	updated timestamp not null,
	instance_id integer not null references ttrss_linked_instances(id) ON DELETE CASCADE,
	subscribers integer not null);

create table ttrss_plugin_storage (
	id serial not null primary key,
	name varchar(100) not null,
	owner_uid integer not null references ttrss_users(id) ON DELETE CASCADE,
	content text not null);

create table ttrss_error_log(
	id serial not null primary key,
	owner_uid integer references ttrss_users(id) ON DELETE SET NULL,
	errno integer not null,
	errstr text not null,
	filename text not null,
	lineno integer not null,
	context text not null,
	created_at timestamp not null);

create table ttrss_scheduled_tasks(
	id serial not null primary key,
	task_name varchar(250) unique not null,
	last_duration integer not null,
	last_rc integer not null,
	last_run timestamp not null default NOW(),
	last_cron_expression varchar(250) not null,
	owner_uid integer default null references ttrss_users(id) ON DELETE CASCADE);

commit;
