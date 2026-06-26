import os

def replace_in_file(path, old, new):
    if not os.path.exists(path):
        print(f"Error: {path} does not exist")
        return
    with open(path, 'r', encoding='utf-8') as f:
        content = f.read()
    if old in content:
        content = content.replace(old, new)
        with open(path, 'w', encoding='utf-8') as f:
            f.write(content)
        print(f"Successfully updated {path}")
    else:
        # Try normalizing whitespace if direct match fails
        normalized_content = " ".join(content.split())
        normalized_old = " ".join(old.split())
        if normalized_old in normalized_content:
            # If normalized exists, we can do a line by line replacement or search
            print(f"Found normalized match in {path}, performing line replace")
            lines = content.splitlines()
            old_lines = old.splitlines()
            # simple replacement
            content = content.replace(old, new)
            with open(path, 'w', encoding='utf-8') as f:
                f.write(content)
        else:
            print(f"Old content not found in {path}")

# File 1: getCompleatedListForVender.php
old_vender = """    CASE 
        WHEN b.trip_type = 'Local-taxi' THEN b.vendor_amount - 100 
        ELSE b.vendor_amount 
    END AS vendor_amount,"""
new_vender = "    b.vendor_amount AS vendor_amount,"
replace_in_file("/var/www/html/driver2025/getCompleatedListForVender.php", old_vender, new_vender)

# File 2: getBookings.php
old_bookings = """            CASE 
                WHEN b.trip_type = 'Local-taxi' THEN b.total_amount - 100 
                ELSE b.total_amount 
            END AS total_amount,"""
new_bookings = "            b.total_amount AS total_amount,"
replace_in_file("/var/www/html/driver2025/getBookings.php", old_bookings, new_bookings)
