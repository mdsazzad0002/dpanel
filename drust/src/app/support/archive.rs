//! Reading and unpacking archives, and copying folders, without the `tar`,
//! `unzip`, `zipinfo`, `gzip`, `zstd` or `cp` programs, so it works the same
//! on every OS. Archives come from customers and other panels, so links,
//! devices, absolute paths and `..` are refused before anything is written,
//! owners are never restored and setuid-style bits are dropped.

use std::{
    fs,
    io::{self, Read},
    path::{Component, Path, PathBuf},
};

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum EntryKind {
    File,
    Dir,
    Symlink,
    HardLink,
    /// A device, FIFO or anything else.
    Other,
}

/// How much an unpack trusts the archive.
#[derive(Default)]
pub struct Options<'a> {
    /// Unpack symlinks and hard links too, for dPanel's own backups of a
    /// home folder. Every entry is still written inside the target, and
    /// never through a link already unpacked.
    pub links: bool,
    /// Called as the compressed file is read: bytes read, file size.
    pub progress: Option<&'a dyn Fn(u64, u64)>,
}

#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Entry {
    /// The name as stored, for example `./public_html/index.php`.
    pub name: String,
    pub kind: EntryKind,
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
enum Format {
    Zip,
    Gzip,
    Zstd,
    Plain,
}

fn magic(path: &Path) -> Result<[u8; 4], String> {
    let mut bytes = [0u8; 4];
    let mut file = fs::File::open(path).map_err(|e| format!("cannot open {}: {e}", path.display()))?;
    let mut read = 0;
    while read < bytes.len() {
        match file.read(&mut bytes[read..]) {
            Ok(0) => break,
            Ok(n) => read += n,
            Err(e) => return Err(e.to_string()),
        }
    }
    Ok(bytes)
}

fn format_of(path: &Path) -> Result<Format, String> {
    Ok(match magic(path)? {
        [0x50, 0x4b, 0x03 | 0x05, 0x04 | 0x06] => Format::Zip,
        [0x1f, 0x8b, ..] => Format::Gzip,
        [0x28, 0xb5, 0x2f, 0xfd] => Format::Zstd,
        _ => Format::Plain,
    })
}

/// The file's contents with gzip or zstd taken off, chosen by magic bytes
/// like `tar` does, so the name does not matter.
fn decoded(path: &Path) -> Result<Box<dyn Read>, String> {
    decoded_with(path, None)
}

struct Counting<'a, R> {
    inner: R,
    read: u64,
    total: u64,
    progress: &'a dyn Fn(u64, u64),
}

impl<R: Read> Read for Counting<'_, R> {
    fn read(&mut self, buffer: &mut [u8]) -> io::Result<usize> {
        let read = self.inner.read(buffer)?;
        self.read += read as u64;
        (self.progress)(self.read, self.total);
        Ok(read)
    }
}

fn decoded_with<'a>(path: &Path, progress: Option<&'a dyn Fn(u64, u64)>) -> Result<Box<dyn Read + 'a>, String> {
    let file = fs::File::open(path).map_err(|e| format!("cannot open {}: {e}", path.display()))?;
    let file: Box<dyn Read + 'a> = match progress {
        Some(progress) => {
            let total = file.metadata().map(|m| m.len()).unwrap_or(0).max(1);
            Box::new(Counting { inner: file, read: 0, total, progress })
        }
        None => Box::new(file),
    };
    Ok(match format_of(path)? {
        Format::Gzip => Box::new(flate2::read::MultiGzDecoder::new(io::BufReader::new(file))),
        Format::Zstd => Box::new(zstd::stream::read::Decoder::new(file).map_err(|e| e.to_string())?),
        Format::Plain | Format::Zip => Box::new(io::BufReader::new(file)),
    })
}

/// Writes `path` without gzip or zstd to `target`. `false` when it was not
/// compressed, and nothing is written.
pub fn decompress(path: &Path, target: &Path) -> Result<bool, String> {
    if !matches!(format_of(path)?, Format::Gzip | Format::Zstd) {
        return Ok(false);
    }
    let mut reader = decoded(path)?;
    let mut output = fs::File::create(target).map_err(|e| e.to_string())?;
    io::copy(&mut reader, &mut output).map_err(|e| format!("cannot decompress {}: {e}", path.display()))?;
    Ok(true)
}

fn tar_kind(kind: tar::EntryType) -> Option<EntryKind> {
    match kind {
        tar::EntryType::Regular | tar::EntryType::Continuous => Some(EntryKind::File),
        tar::EntryType::Directory => Some(EntryKind::Dir),
        tar::EntryType::Symlink => Some(EntryKind::Symlink),
        tar::EntryType::Link => Some(EntryKind::HardLink),
        // Metadata records, not members.
        tar::EntryType::XGlobalHeader | tar::EntryType::XHeader | tar::EntryType::GNULongName | tar::EntryType::GNULongLink => None,
        _ => Some(EntryKind::Other),
    }
}

/// Every member of a zip or a tar (plain, gzip or zstd).
pub fn list(path: &Path) -> Result<Vec<Entry>, String> {
    list_with(path, None)
}

/// [`list`], reporting how far through the file it is.
pub fn list_with(path: &Path, progress: Option<&dyn Fn(u64, u64)>) -> Result<Vec<Entry>, String> {
    if format_of(path)? == Format::Zip {
        let mut archive = zip::ZipArchive::new(fs::File::open(path).map_err(|e| e.to_string())?)
            .map_err(|e| format!("invalid zip archive: {e}"))?;
        let mut entries = Vec::with_capacity(archive.len());
        for index in 0..archive.len() {
            let file = archive.by_index_raw(index).map_err(|e| e.to_string())?;
            let kind = if file.is_symlink() {
                EntryKind::Symlink
            } else if file.is_dir() {
                EntryKind::Dir
            } else if file.is_file() {
                EntryKind::File
            } else {
                EntryKind::Other
            };
            entries.push(Entry { name: file.name().to_string(), kind });
        }
        return Ok(entries);
    }
    let mut archive = tar::Archive::new(decoded_with(path, progress)?);
    let mut entries = Vec::new();
    for entry in archive.entries().map_err(|e| format!("invalid archive: {e}"))? {
        let entry = entry.map_err(|e| format!("invalid archive: {e}"))?;
        let Some(kind) = tar_kind(entry.header().entry_type()) else {
            continue;
        };
        let name = String::from_utf8_lossy(&entry.path_bytes()).into_owned();
        entries.push(Entry { name, kind });
    }
    Ok(entries)
}

/// A name with no absolute part and no `..`. `./x` is fine.
pub fn safe_relative(name: &str) -> bool {
    let path = Path::new(name);
    !name.starts_with('/')
        && !name.starts_with('\\')
        && !path.is_absolute()
        && path
            .components()
            .all(|c| matches!(c, Component::Normal(_) | Component::CurDir))
}

/// `./a/b/` and `a/b` are the same member.
pub fn normalized(name: &str) -> String {
    name.trim_start_matches("./").trim_end_matches('/').to_string()
}

/// Refuses an archive with links, special files or unsafe names, so the
/// caller can trust what [`extract_all`] would write.
#[cfg(test)]
pub fn checked_list(path: &Path) -> Result<Vec<Entry>, String> {
    checked(list(path)?, false)
}

fn checked(entries: Vec<Entry>, links: bool) -> Result<Vec<Entry>, String> {
    let allowed = |kind: EntryKind| match kind {
        EntryKind::File | EntryKind::Dir => true,
        EntryKind::Symlink | EntryKind::HardLink => links,
        EntryKind::Other => false,
    };
    if entries.iter().any(|e| !allowed(e.kind)) {
        return Err("the archive contains links or special files, which are not restored".into());
    }
    if entries.iter().any(|e| !safe_relative(&e.name)) {
        return Err("the archive contains an unsafe path".into());
    }
    Ok(entries)
}

fn wanted(name: &str, member: Option<&str>) -> bool {
    match member {
        None => true,
        Some(member) => {
            let name = normalized(name);
            name == member || name.starts_with(&format!("{member}/"))
        }
    }
}

/// Unpacks everything, or only `member` and what is under it, into `into`.
/// Fails before writing anything when the archive holds a link, a special
/// file or an unsafe name.
pub fn extract(path: &Path, into: &Path, member: Option<&str>) -> Result<(), String> {
    extract_with(path, into, member, &Options::default())
}

/// [`extract`] with [`Options`]. The archive is read twice: once to check
/// every entry, once to unpack.
pub fn extract_with(path: &Path, into: &Path, member: Option<&str>, options: &Options) -> Result<(), String> {
    checked(list(path)?, options.links)?;
    if options.links && format_of(path)? == Format::Zip {
        return Err("links are only unpacked from a tar".into());
    }
    let member = member.map(normalized);
    let member = member.as_deref();
    fs::create_dir_all(into).map_err(|e| e.to_string())?;
    let mut found = member.is_none();
    if format_of(path)? == Format::Zip {
        let mut archive = zip::ZipArchive::new(fs::File::open(path).map_err(|e| e.to_string())?)
            .map_err(|e| format!("invalid zip archive: {e}"))?;
        for index in 0..archive.len() {
            let mut file = archive.by_index(index).map_err(|e| e.to_string())?;
            if !wanted(file.name(), member) {
                continue;
            }
            found = true;
            let relative = file.enclosed_name().ok_or("the archive contains an unsafe path")?;
            let target = into.join(relative);
            if file.is_dir() {
                fs::create_dir_all(&target).map_err(|e| e.to_string())?;
                continue;
            }
            if let Some(parent) = target.parent() {
                fs::create_dir_all(parent).map_err(|e| e.to_string())?;
            }
            let mut output = fs::File::create(&target).map_err(|e| e.to_string())?;
            io::copy(&mut file, &mut output).map_err(|e| format!("cannot extract {}: {e}", file.name()))?;
            if let Some(mode) = file.unix_mode() {
                set_mode(&target, mode)?;
            }
        }
    } else {
        let mut archive = tar::Archive::new(decoded_with(path, options.progress)?);
        archive.set_preserve_permissions(false);
        archive.set_preserve_ownerships(false);
        archive.set_unpack_xattrs(false);
        archive.set_overwrite(true);
        for entry in archive.entries().map_err(|e| format!("invalid archive: {e}"))? {
            let mut entry = entry.map_err(|e| format!("invalid archive: {e}"))?;
            if tar_kind(entry.header().entry_type()).is_none() {
                continue;
            }
            let name = String::from_utf8_lossy(&entry.path_bytes()).into_owned();
            if !wanted(&name, member) {
                continue;
            }
            found = true;
            if !entry.unpack_in(into).map_err(|e| format!("cannot extract {name}: {e}"))? {
                return Err("the archive contains an unsafe path".into());
            }
        }
    }
    if !found {
        return Err(format!("{} is not in the archive", member.unwrap_or_default()));
    }
    Ok(())
}

/// Unpacks the whole archive.
pub fn extract_all(path: &Path, into: &Path) -> Result<(), String> {
    extract(path, into, None)
}

/// One regular member's bytes. `x` also finds a member stored as `./x`.
pub fn read_member(path: &Path, name: &str) -> Option<Vec<u8>> {
    let name = normalized(name);
    if format_of(path).ok()? == Format::Zip {
        let mut archive = zip::ZipArchive::new(fs::File::open(path).ok()?).ok()?;
        for index in 0..archive.len() {
            let mut file = archive.by_index(index).ok()?;
            if file.is_file() && !file.is_symlink() && normalized(file.name()) == name {
                let mut bytes = Vec::new();
                file.read_to_end(&mut bytes).ok()?;
                return Some(bytes);
            }
        }
        return None;
    }
    let mut archive = tar::Archive::new(decoded(path).ok()?);
    for entry in archive.entries().ok()? {
        let mut entry = entry.ok()?;
        if tar_kind(entry.header().entry_type()) != Some(EntryKind::File) {
            continue;
        }
        if normalized(&String::from_utf8_lossy(&entry.path_bytes())) == name {
            let mut bytes = Vec::new();
            entry.read_to_end(&mut bytes).ok()?;
            return Some(bytes);
        }
    }
    None
}

/// Writes a gzip tar of `sources`, each `(name in the archive, folder or
/// file)`, like `tar -czf`: symlinks stay links, modes, owners and times are
/// kept, sockets and devices are left out as GNU tar does.
pub fn create_tar_gz(target: &Path, sources: &[(&str, &Path)]) -> Result<(), String> {
    let output = fs::File::create(target).map_err(|e| format!("cannot create {}: {e}", target.display()))?;
    let encoder = flate2::write::GzEncoder::new(io::BufWriter::new(output), flate2::Compression::default());
    let mut builder = tar::Builder::new(encoder);
    builder.follow_symlinks(false);
    builder.mode(tar::HeaderMode::Complete);
    for (name, source) in sources {
        append_tree(&mut builder, Path::new(name), source)?;
    }
    builder
        .into_inner()
        .and_then(|encoder| encoder.finish())
        .and_then(|mut writer| io::Write::flush(&mut writer))
        .map_err(|e| format!("cannot write {}: {e}", target.display()))
}

fn append_tree<W: io::Write>(builder: &mut tar::Builder<W>, name: &Path, source: &Path) -> Result<(), String> {
    let metadata = fs::symlink_metadata(source).map_err(|e| format!("cannot read {}: {e}", source.display()))?;
    let kind = metadata.file_type();
    if !(kind.is_dir() || kind.is_file() || kind.is_symlink()) {
        return Ok(());
    }
    builder
        .append_path_with_name(source, name)
        .map_err(|e| format!("cannot add {}: {e}", source.display()))?;
    if kind.is_dir() {
        let mut children: Vec<_> = fs::read_dir(source)
            .map_err(|e| format!("cannot read {}: {e}", source.display()))?
            .filter_map(Result::ok)
            .collect();
        children.sort_by_key(|entry| entry.file_name());
        for child in children {
            append_tree(builder, &name.join(child.file_name()), &child.path())?;
        }
    }
    Ok(())
}

/// The permission bits, without setuid, setgid or sticky, where the OS has
/// modes. Elsewhere nothing.
fn set_mode(path: &Path, mode: u32) -> Result<(), String> {
    if crate::platform::os_has_no_file_modes() {
        return Ok(());
    }
    crate::platform::files()
        .set_mode(path, crate::platform::fs::FileMode::Raw(mode & 0o777))
        .map_err(|e| e.to_string())
}

/// `cp -a from/. to`: copies a file or folder, merging into an existing
/// folder instead of nesting inside it. Keeps modes, file times and
/// symlinks (as links, never followed); owners are left to the caller.
pub fn copy_tree(from: &Path, to: &Path) -> Result<(), String> {
    let metadata = fs::symlink_metadata(from).map_err(|e| format!("cannot read {}: {e}", from.display()))?;
    if metadata.file_type().is_symlink() {
        let target = fs::read_link(from).map_err(|e| e.to_string())?;
        if fs::symlink_metadata(to).is_ok() {
            if to.is_dir() && !fs::symlink_metadata(to).map(|m| m.file_type().is_symlink()).unwrap_or(false) {
                fs::remove_dir_all(to).map_err(|e| e.to_string())?;
            } else {
                crate::platform::files().remove_file(to).map_err(|e| e.to_string())?;
            }
        }
        return crate::platform::files().symlink(&target, to).map_err(|e| e.to_string());
    }
    let files = crate::platform::files();
    if metadata.is_dir() {
        if fs::symlink_metadata(to).map(|m| !m.is_dir()).unwrap_or(false) {
            files.remove_file(to).map_err(|e| e.to_string())?;
        }
        fs::create_dir_all(to).map_err(|e| e.to_string())?;
        for entry in fs::read_dir(from).map_err(|e| e.to_string())? {
            let entry = entry.map_err(|e| e.to_string())?;
            copy_tree(&entry.path(), &to.join(entry.file_name()))?;
        }
        if let Some(mode) = files.mode_bits(&metadata) {
            set_mode(to, mode)?;
        }
        return Ok(());
    }
    if !metadata.is_file() {
        // A FIFO or device: cp -a would recreate it; a site never needs one.
        return Ok(());
    }
    if let Some(parent) = to.parent() {
        fs::create_dir_all(parent).map_err(|e| e.to_string())?;
    }
    if fs::symlink_metadata(to).map(|m| m.file_type().is_symlink()).unwrap_or(false) {
        files.remove_file(to).map_err(|e| e.to_string())?;
    }
    fs::copy(from, to).map_err(|e| format!("cannot copy {}: {e}", from.display()))?;
    if let Some(mode) = files.mode_bits(&metadata) {
        set_mode(to, mode)?;
    }
    if let Ok(modified) = metadata.modified() {
        if let Ok(file) = fs::OpenOptions::new().write(true).open(to) {
            let _ = file.set_modified(modified);
        }
    }
    Ok(())
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::io::Write;

    fn scratch(name: &str) -> PathBuf {
        let dir = std::env::temp_dir().join(format!("dpanel-archive-{name}-{}", std::process::id()));
        let _ = fs::remove_dir_all(&dir);
        fs::create_dir_all(&dir).unwrap();
        dir
    }

    fn tar_bytes(entries: &[(&str, tar::EntryType, &[u8])]) -> Vec<u8> {
        let mut builder = tar::Builder::new(Vec::new());
        for (name, kind, body) in entries {
            let mut header = tar::Header::new_gnu();
            header.set_entry_type(*kind);
            header.set_size(body.len() as u64);
            header.set_mode(if *kind == tar::EntryType::Directory { 0o755 } else { 0o4755 });
            if *kind == tar::EntryType::Symlink {
                header.set_link_name("/etc/passwd").unwrap();
            }
            // set_path refuses `..`; write the raw name to test what a hostile archive holds.
            let raw = &mut header.as_old_mut().name;
            raw[..name.len()].copy_from_slice(name.as_bytes());
            header.set_cksum();
            builder.append(&header, *body).unwrap();
        }
        builder.into_inner().unwrap()
    }

    fn gz(bytes: &[u8]) -> Vec<u8> {
        let mut encoder = flate2::write::GzEncoder::new(Vec::new(), flate2::Compression::default());
        encoder.write_all(bytes).unwrap();
        encoder.finish().unwrap()
    }

    #[test]
    fn lists_and_extracts_plain_gzip_and_zstd_tars() {
        let dir = scratch("formats");
        let plain = tar_bytes(&[
            ("./site/", tar::EntryType::Directory, b""),
            ("./site/index.php", tar::EntryType::Regular, b"<?php"),
        ]);
        let variants = [
            ("a.tar", plain.clone()),
            ("b.tar.gz", gz(&plain)),
            ("c.tar.zst", zstd::stream::encode_all(&plain[..], 3).unwrap()),
            ("misnamed.zip", gz(&plain)),
        ];
        for (name, bytes) in variants {
            let archive = dir.join(name);
            fs::write(&archive, bytes).unwrap();
            let names: Vec<_> = checked_list(&archive).unwrap().into_iter().map(|e| normalized(&e.name)).collect();
            assert_eq!(names, ["site", "site/index.php"], "{name}");
            assert_eq!(read_member(&archive, "site/index.php").unwrap(), b"<?php");
            let out = dir.join(format!("out-{name}"));
            extract_all(&archive, &out).unwrap();
            assert_eq!(fs::read(out.join("site/index.php")).unwrap(), b"<?php");
            if !crate::platform::os_has_no_file_modes() {
                let mode = crate::platform::files().mode_bits(&fs::metadata(out.join("site/index.php")).unwrap()).unwrap();
                assert_eq!(mode & 0o7000, 0, "setuid must be dropped for {name}");
            }
        }
        fs::remove_dir_all(&dir).ok();
    }

    #[test]
    fn extracts_one_member_and_what_is_under_it() {
        let dir = scratch("member");
        let archive = dir.join("a.tar");
        fs::write(
            &archive,
            tar_bytes(&[
                ("homedir/a.txt", tar::EntryType::Regular, b"a"),
                ("homedir/sub/b.txt", tar::EntryType::Regular, b"b"),
                ("mysql/db.sql", tar::EntryType::Regular, b"sql"),
            ]),
        )
        .unwrap();
        let out = dir.join("out");
        extract(&archive, &out, Some("./homedir/")).unwrap();
        assert!(out.join("homedir/sub/b.txt").is_file());
        assert!(!out.join("mysql").exists());
        assert!(extract(&archive, &out, Some("missing")).is_err());
        fs::remove_dir_all(&dir).ok();
    }

    #[test]
    fn refuses_links_specials_and_unsafe_names_before_writing() {
        let dir = scratch("refuse");
        let cases: [(&str, Vec<u8>); 3] = [
            ("link.tar", tar_bytes(&[("ok.txt", tar::EntryType::Regular, b"x"), ("l", tar::EntryType::Symlink, b"")])),
            ("fifo.tar", tar_bytes(&[("ok.txt", tar::EntryType::Regular, b"x"), ("f", tar::EntryType::Fifo, b"")])),
            ("dots.tar", tar_bytes(&[("ok.txt", tar::EntryType::Regular, b"x"), ("../evil", tar::EntryType::Regular, b"x")])),
        ];
        for (name, bytes) in cases {
            let archive = dir.join(name);
            fs::write(&archive, bytes).unwrap();
            let out = dir.join(format!("out-{name}"));
            assert!(extract_all(&archive, &out).is_err(), "{name}");
            assert!(!out.join("ok.txt").exists(), "{name} wrote before refusing");
        }
        assert!(!dir.join("evil").exists());
        fs::remove_dir_all(&dir).ok();
    }

    #[test]
    fn zip_round_trip_and_unsafe_zip_names() {
        let dir = scratch("zip");
        let archive = dir.join("site.zip");
        {
            let mut writer = zip::ZipWriter::new(fs::File::create(&archive).unwrap());
            let options = zip::write::SimpleFileOptions::default().unix_permissions(0o4644);
            writer.add_directory("public/", options).unwrap();
            writer.start_file("public/index.html", options).unwrap();
            writer.write_all(b"hi").unwrap();
            writer.finish().unwrap();
        }
        let names: Vec<_> = checked_list(&archive).unwrap().into_iter().map(|e| normalized(&e.name)).collect();
        assert_eq!(names, ["public", "public/index.html"]);
        extract_all(&archive, &dir.join("out")).unwrap();
        assert_eq!(fs::read(dir.join("out/public/index.html")).unwrap(), b"hi");
        assert_eq!(read_member(&archive, "public/index.html").unwrap(), b"hi");

        let evil = dir.join("evil.zip");
        {
            let mut writer = zip::ZipWriter::new(fs::File::create(&evil).unwrap());
            writer.start_file("../evil.txt", zip::write::SimpleFileOptions::default()).unwrap();
            writer.write_all(b"x").unwrap();
            writer.finish().unwrap();
        }
        assert!(checked_list(&evil).is_err());
        assert!(extract_all(&evil, &dir.join("out2")).is_err());
        fs::remove_dir_all(&dir).ok();
    }

    #[test]
    fn decompress_only_touches_compressed_files() {
        let dir = scratch("decompress");
        fs::write(dir.join("a.sql.gz"), gz(b"SELECT 1;")).unwrap();
        fs::write(dir.join("b.sql"), b"SELECT 2;").unwrap();
        fs::write(dir.join("c.zst"), zstd::stream::encode_all(&b"SELECT 3;"[..], 3).unwrap()).unwrap();
        assert!(decompress(&dir.join("a.sql.gz"), &dir.join("a.out")).unwrap());
        assert_eq!(fs::read(dir.join("a.out")).unwrap(), b"SELECT 1;");
        assert!(!decompress(&dir.join("b.sql"), &dir.join("b.out")).unwrap());
        assert!(!dir.join("b.out").exists());
        assert!(decompress(&dir.join("c.zst"), &dir.join("c.out")).unwrap());
        assert_eq!(fs::read(dir.join("c.out")).unwrap(), b"SELECT 3;");
        fs::remove_dir_all(&dir).ok();
    }

    #[test]
    fn copy_tree_merges_and_keeps_links_and_modes() {
        let dir = scratch("copy");
        let from = dir.join("from");
        fs::create_dir_all(from.join("sub")).unwrap();
        fs::write(from.join("sub/a.txt"), "new").unwrap();
        fs::write(from.join("run.sh"), "#!/bin/sh").unwrap();
        let to = dir.join("to");
        fs::create_dir_all(&to).unwrap();
        fs::write(to.join("keep.txt"), "old").unwrap();
        let unix = !crate::platform::os_has_no_file_modes();
        if unix {
            crate::platform::files().set_mode(&from.join("run.sh"), crate::platform::fs::FileMode::Executable).unwrap();
            crate::platform::files().symlink(Path::new("sub/a.txt"), &from.join("link")).unwrap();
        }
        copy_tree(&from, &to).unwrap();
        assert_eq!(fs::read_to_string(to.join("sub/a.txt")).unwrap(), "new");
        assert_eq!(fs::read_to_string(to.join("keep.txt")).unwrap(), "old");
        assert!(!to.join("from").exists(), "must merge, not nest");
        if unix {
            let mode = crate::platform::files().mode_bits(&fs::metadata(to.join("run.sh")).unwrap()).unwrap();
            assert_eq!(mode & 0o777, 0o755);
            assert_eq!(fs::read_link(to.join("link")).unwrap(), Path::new("sub/a.txt"));
        }
        // A single file copies to the named path.
        copy_tree(&from.join("run.sh"), &dir.join("single/run.sh")).unwrap();
        assert!(dir.join("single/run.sh").is_file());
        fs::remove_dir_all(&dir).ok();
    }

    #[test]
    fn cpmove_round_trip_keeps_files_links_and_reads_with_gnu_tar() {
        let dir = scratch("roundtrip");
        let home = dir.join("home");
        fs::create_dir_all(home.join("public_html/storage")).unwrap();
        fs::write(home.join("public_html/index.php"), "<?php echo 1;").unwrap();
        fs::write(home.join("public_html/storage/a.txt"), "a").unwrap();
        let meta = dir.join("cpmove-bob");
        fs::create_dir_all(meta.join("meta")).unwrap();
        fs::write(meta.join("meta/dpanel-manifest.json"), "{}").unwrap();
        let unix = !crate::platform::os_has_no_file_modes();
        if unix {
            crate::platform::files().symlink(Path::new("storage"), &home.join("public_html/link")).unwrap();
        }
        let archive = dir.join("cpmove-bob.tar.gz");
        create_tar_gz(&archive, &[("cpmove-bob", &meta), ("cpmove-bob/homedir", &home)]).unwrap();

        let restored = dir.join("restored");
        extract_with(&archive, &restored, None, &Options { links: true, progress: None }).unwrap();
        let root = restored.join("cpmove-bob");
        assert_eq!(fs::read_to_string(root.join("meta/dpanel-manifest.json")).unwrap(), "{}");
        assert_eq!(fs::read_to_string(root.join("homedir/public_html/index.php")).unwrap(), "<?php echo 1;");
        assert_eq!(fs::read_to_string(root.join("homedir/public_html/storage/a.txt")).unwrap(), "a");
        if unix {
            assert_eq!(fs::read_link(root.join("homedir/public_html/link")).unwrap(), Path::new("storage"));
            // Without `links` the same archive is refused, as a foreign one would be.
            assert!(extract_all(&archive, &dir.join("strict")).is_err());
        }

        // The format stays what GNU tar writes and reads.
        if let Ok(out) = std::process::Command::new("tar").arg("-tzf").arg(&archive).output() {
            let names = String::from_utf8_lossy(&out.stdout);
            assert!(out.status.success());
            assert!(names.lines().any(|l| l.trim_end_matches('/') == "cpmove-bob/homedir/public_html/index.php"));
        }
        fs::remove_dir_all(&dir).ok();
    }

    #[test]
    fn progress_reports_up_to_the_file_size() {
        let dir = scratch("progress");
        let archive = dir.join("a.tar.gz");
        fs::write(&archive, gz(&tar_bytes(&[("a.txt", tar::EntryType::Regular, b"hello")]))).unwrap();
        let seen = std::cell::Cell::new((0u64, 0u64));
        let report = |read: u64, total: u64| seen.set((read, total));
        list_with(&archive, Some(&report)).unwrap();
        let (read, total) = seen.get();
        assert_eq!(total, fs::metadata(&archive).unwrap().len());
        assert_eq!(read, total);
        fs::remove_dir_all(&dir).ok();
    }
}
