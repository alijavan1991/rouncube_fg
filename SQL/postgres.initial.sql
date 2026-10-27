CREATE TABLE zemzemeh_dlp_provenance (
    id serial PRIMARY KEY,
    user_id integer NOT NULL REFERENCES users (user_id) ON DELETE CASCADE ON UPDATE CASCADE,
    oidc_sub varchar(255) NOT NULL,
    session_hash varchar(64) NOT NULL,
    compose_id varchar(128) NOT NULL,
    upload_id varchar(64) NOT NULL,
    zz_file_id varchar(32) NOT NULL,
    zz_owner varchar(255) NOT NULL,
    zz_version varchar(64) NOT NULL,
    sha256 varchar(64) NOT NULL,
    filename varchar(1024) NOT NULL,
    mimetype varchar(255) NOT NULL,
    size bigint NOT NULL,
    spool_name varchar(64) NOT NULL,
    decision varchar(16) NOT NULL,
    selected_at timestamp with time zone NOT NULL,
    expires_at timestamp with time zone NOT NULL,
    consumed_at timestamp with time zone DEFAULT NULL
);

CREATE UNIQUE INDEX ix_zemzemeh_dlp_provenance_upload ON zemzemeh_dlp_provenance (session_hash, upload_id);
CREATE INDEX ix_zemzemeh_dlp_provenance_compose ON zemzemeh_dlp_provenance (compose_id);
CREATE INDEX ix_zemzemeh_dlp_provenance_hash ON zemzemeh_dlp_provenance (user_id, sha256);
CREATE INDEX ix_zemzemeh_dlp_provenance_expires ON zemzemeh_dlp_provenance (expires_at);

INSERT INTO system (name, value) VALUES ('zemzemeh_dlp-version', '2026102700');
