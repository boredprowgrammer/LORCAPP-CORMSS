#!/bin/bash
# Script to systematically add dark mode classes to PHP files

echo "Adding dark mode classes to PHP files..."

# Function to add dark mode class after a light mode class
add_dark_class() {
    local file="$1"
    local pattern="$2"
    local replacement="$3"
    
    if grep -q "$pattern" "$file" 2>/dev/null; then
        sed -i "s/$pattern/$replacement/g" "$file"
        echo "✓ Updated: $file"
    fi
}

# Common patterns to update
files=$(find . -name "*.php" -type f ! -path "./vendor/*" ! -path "./node_modules/*")

for file in $files; do
    # bg-white without dark mode
    sed -i 's/class="\([^"]*\)bg-white\([^"]*\)"/class="\1bg-white dark:bg-gray-800\2"/g' "$file" 2>/dev/null
    
    # text-gray-900 without dark mode  
    sed -i 's/class="\([^"]*\)text-gray-900\([^"]*\)"/class="\1text-gray-900 dark:text-gray-100\2"/g' "$file" 2>/dev/null
    
    # text-gray-700 without dark mode
    sed -i 's/class="\([^"]*\)text-gray-700\([^"]*\)"/class="\1text-gray-700 dark:text-gray-300\2"/g' "$file" 2>/dev/null
    
    # text-gray-600 without dark mode
    sed -i 's/class="\([^"]*\)text-gray-600\([^"]*\)"/class="\1text-gray-600 dark:text-gray-400\2"/g' "$file" 2>/dev/null
    
    # text-gray-500 without dark mode
    sed -i 's/class="\([^"]*\)text-gray-500\([^"]*\)"/class="\1text-gray-500 dark:text-gray-400\2"/g" "$file" 2>/dev/null
    
    # border-gray-200 without dark mode
    sed -i 's/class="\([^"]*\)border-gray-200\([^"]*\)"/class="\1border-gray-200 dark:border-gray-700\2"/g' "$file" 2>/dev/null
    
    # border-gray-300 without dark mode
    sed -i 's/class="\([^"]*\)border-gray-300\([^"]*\)"/class="\1border-gray-300 dark:border-gray-600\2"/g' "$file" 2>/dev/null
    
    # bg-gray-50 without dark mode
    sed -i 's/class="\([^"]*\)bg-gray-50\([^"]*\)"/class="\1bg-gray-50 dark:bg-gray-900\2"/g' "$file" 2>/dev/null
    
    # bg-gray-100 without dark mode
    sed -i 's/class="\([^"]*\)bg-gray-100\([^"]*\)"/class="\1bg-gray-100 dark:bg-gray-800\2"/g' "$file" 2>/dev/null
done

echo "Dark mode classes added to all PHP files!"
echo "Note: Some manual adjustments may be needed for specific contexts."
