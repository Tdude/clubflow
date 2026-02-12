#!/usr/bin/env python3
"""Simple PO to MO file compiler"""
import struct
import array
import os

def generate_mo(po_file, mo_file):
    """Convert PO file to MO file format"""
    
    # Read PO file
    with open(po_file, 'r', encoding='utf-8') as f:
        lines = f.readlines()
    
    # Parse PO file
    messages = {}
    msgid = None
    msgstr = None
    in_msgid = False
    in_msgstr = False
    
    for line in lines:
        line = line.strip()
        
        if line.startswith('msgid '):
            if msgid is not None and msgstr is not None:
                messages[msgid] = msgstr
            msgid = line[6:].strip('"')
            in_msgid = True
            in_msgstr = False
        elif line.startswith('msgstr '):
            msgstr = line[7:].strip('"')
            in_msgid = False
            in_msgstr = True
        elif line.startswith('"') and in_msgid:
            msgid += line.strip('"')
        elif line.startswith('"') and in_msgstr:
            msgstr += line.strip('"')
        elif line == '':
            if msgid is not None and msgstr is not None:
                messages[msgid] = msgstr
            msgid = None
            msgstr = None
            in_msgid = False
            in_msgstr = False
    
    # Don't forget the last entry
    if msgid is not None and msgstr is not None:
        messages[msgid] = msgstr
    
    # Remove empty msgid (header)
    if '' in messages:
        del messages['']
    
    # Create MO file
    keys = sorted(messages.keys())
    offsets = []
    ids = b''
    strs = b''
    
    for key in keys:
        msg = messages[key]
        offsets.append((len(ids), len(key), len(strs), len(msg)))
        ids += key.encode('utf-8') + b'\x00'
        strs += msg.encode('utf-8') + b'\x00'
    
    # MO file format
    keystart = 7 * 4 + 16 * len(keys)
    valuestart = keystart + len(ids)
    
    # Create the hash table (we'll use a simple approach with no hash table)
    koffsets = []
    voffsets = []
    for o1, l1, o2, l2 in offsets:
        koffsets += [l1, o1 + keystart]
        voffsets += [l2, o2 + valuestart]
    
    # Write MO file
    with open(mo_file, 'wb') as f:
        # Magic number
        f.write(struct.pack('I', 0x950412de))
        # Version
        f.write(struct.pack('I', 0))
        # Number of entries
        f.write(struct.pack('I', len(keys)))
        # Offset of key index
        f.write(struct.pack('I', 7 * 4))
        # Offset of value index
        f.write(struct.pack('I', 7 * 4 + len(keys) * 8))
        # Size of hash table
        f.write(struct.pack('I', 0))
        # Offset of hash table
        f.write(struct.pack('I', 0))
        
        # Write key index
        for i in range(0, len(koffsets), 2):
            f.write(struct.pack('I', koffsets[i]))
            f.write(struct.pack('I', koffsets[i + 1]))
        
        # Write value index
        for i in range(0, len(voffsets), 2):
            f.write(struct.pack('I', voffsets[i]))
            f.write(struct.pack('I', voffsets[i + 1]))
        
        # Write keys
        f.write(ids)
        # Write values
        f.write(strs)
    
    print(f"Compiled {po_file} -> {mo_file}")
    print(f"Translated {len(keys)} strings")

if __name__ == '__main__':
    generate_mo('/home/claude/languages/clubcal-lite-sv_SE.po', 
                '/home/claude/languages/clubcal-lite-sv_SE.mo')
