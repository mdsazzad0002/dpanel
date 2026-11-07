use std::{fs::File, io, io::Read};

pub fn fill(buffer: &mut [u8]) -> io::Result<()> {
    File::open("/dev/urandom")?.read_exact(buffer)
}
