pub(crate) mod request;

pub(crate) fn run_database_request(
    action: String,
    db_name: String,
    db_user: String,
    db_password: String,
    db_host: String,
    db_port: u16,
    charset: String,
    collation: String,
) -> Result<String, String> {
    request::run(request::Options {
        action,
        db_name,
        db_user,
        db_password,
        db_host,
        db_port,
        charset,
        collation,
    })
}
