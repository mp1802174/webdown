import time
import pymysql
from pymysql import Error

def test_db_connection(db_config):
    """Tests the connection to the MySQL database using PyMySQL."""
    connection = None
    try:
        print(f"Attempting to connect to MySQL database using PyMySQL: {db_config.get('host')}/{db_config.get('database')}...")
        connection = pymysql.connect(
            host=db_config.get('host'),
            database=db_config.get('database'),
            user=db_config.get('user'),
            password=db_config.get('password'),
            port=db_config.get('port', 3306),
            connect_timeout=15,
            charset='utf8mb4',
            cursorclass=pymysql.cursors.DictCursor
        )
        print(f"Successfully connected to MySQL using PyMySQL!")
        with connection.cursor() as cursor:
            cursor.execute("SELECT VERSION()")
            version = cursor.fetchone()
            print(f"MySQL Server version: {version['VERSION()']}")
            cursor.execute("SELECT DATABASE();")
            db_name = cursor.fetchone()
            print(f"Connected to database: {db_name['DATABASE()']}")
        return True

    except Error as e:
        print(f"Error while connecting to MySQL using PyMySQL: {e}")
        return False
    finally:
        if connection:
            connection.close()
            print("PyMySQL connection is closed")

def get_existing_article_links(db_config):
    """
    Fetches a set of existing article links from the database to prevent duplicates.
    Uses PyMySQL.
    """
    print("Attempting to fetch existing article links from the database...")
    existing_links = set()
    connection = None
    try:
        connection = pymysql.connect(
            host=db_config.get('host'),
            database=db_config.get('database'),
            user=db_config.get('user'),
            password=db_config.get('password'),
            port=db_config.get('port', 3306),
            connect_timeout=15,
            charset='utf8mb4'
        )
        with connection.cursor() as cursor:
            table_name = db_config.get('table', 'wechat_articles') # Use configured table name
            # Ensure the column name matches the one in your CREATE TABLE statement
            link_column_name = '文章链接' 
            query = f"SELECT DISTINCT `{link_column_name}` FROM `{table_name}`"
            cursor.execute(query)
            results = cursor.fetchall()
            existing_links = {row[0] for row in results if row and row[0]} # Fetch the link
            print(f"Fetched {len(existing_links)} existing links from database.")

    except Error as e:
        print(f"Error fetching existing links using PyMySQL: {e}")
        # Return empty set on error to avoid accidentally skipping uploads

    finally:
        if connection:
            connection.close()
            # print("Connection closed after fetching links.") # Optional debug print
    return existing_links

def upload_articles_to_db(articles_data, db_config):
    """
    Uploads a list of new article data to the specified online database.
    Uses PyMySQL and performs batch insert if possible.
    """
    if not articles_data:
        print("No new articles to upload.")
        return {"status": "no_data", "uploaded_count": 0}

    print(f"Attempting to upload {len(articles_data)} new articles using PyMySQL.")
    connection = None
    inserted_count = 0
    try:
        connection = pymysql.connect(
            host=db_config.get('host'),
            database=db_config.get('database'),
            user=db_config.get('user'),
            password=db_config.get('password'),
            port=db_config.get('port', 3306),
            connect_timeout=15,
            charset='utf8mb4'
        )
        with connection.cursor() as cursor:
            table_name = db_config.get('table', 'wechat_articles')
            # Prepare the SQL INSERT statement
            # Column names must match your CREATE TABLE statement exactly
            sql = f"""INSERT INTO `{table_name}` 
                       (`公众号名称`, `分类`, `文章日期`, `文章标题`, `文章链接`, `采集日期`) 
                       VALUES (%s, %s, %s, %s, %s, %s)"""
            
            # Prepare data for executemany (list of tuples)
            data_to_insert = []
            for article in articles_data:
                data_to_insert.append((
                    article.get('公众号名称'),
                    article.get('分类'),
                    article.get('文章日期'),
                    article.get('文章标题'),
                    article.get('文章链接'),
                    article.get('采集日期')
                ))
            
            # Execute the insert statement using executemany for efficiency
            inserted_count = cursor.executemany(sql, data_to_insert)
            connection.commit() # Commit the transaction
            print(f"Successfully inserted {inserted_count} new articles into the database.")

    except Error as e:
        print(f"Error uploading articles using PyMySQL: {e}")
        if connection:
            connection.rollback() # Rollback on error
        # Return error status
        return {"status": "error", "message": str(e), "uploaded_count": 0}
    except Exception as ex:
        # Catch other potential errors during data prep etc.
        print(f"An unexpected error occurred during upload: {ex}")
        if connection:
            connection.rollback()
        return {"status": "error", "message": str(ex), "uploaded_count": 0}

    finally:
        if connection:
            connection.close()
            # print("Connection closed after uploading.") # Optional debug print

    return {"status": "success", "uploaded_count": inserted_count}

if __name__ == '__main__':
    # ... (Example usage can remain for testing, but real data comes from main.py)
    sample_articles = [
        {
            "公众号名称": "测试公众号1", "分类": "公众号", "文章日期": "2024-05-12 10:00:00",
            "文章标题": "第一篇文章标题", "文章链接": "http://example.com/article1", "采集日期": "2024-05-13 09:00:00"
        },
        {
            "公众号名称": "测试公众号2", "分类": "公众号", "文章日期": "2024-05-12 11:00:00",
            "文章标题": "第二篇文章标题", "文章链接": "http://example.com/article2", "采集日期": "2024-05-13 09:00:00"
        }
    ]
    sample_db_config = {
        # --- IMPORTANT: Replace with your ACTUAL credentials for testing --- #
        "type": "MySQL",
        "host": "140.238.201.162",
        "user": "cj",
        "password": "760516",
        "database": "cj",
        "table": "wechat_articles",
        "port": 3306
        # ------------------------------------------------------------------- #
    }

    print("Running db_uploader.py example...")
    # Example usage for connection test
    print("--- Testing DB Connection --- ")
    test_db_connection(sample_db_config) # Test with sample config
    print("------------------------------")
    # Example fetch existing (will now query the actual DB)
    print("--- Fetching Existing Links --- ")
    existing = get_existing_article_links(sample_db_config)
    print(f"Example existing links ({len(existing)}): {list(existing)[:10]}...") # Print first 10
    print("------------------------------")
    # Filter sample articles
    sample_articles_to_upload = [a for a in sample_articles if a['文章链接'] not in existing]

    # Example upload (will now attempt to insert into the actual DB)
    print("--- Attempting Sample Upload --- ")
    if sample_articles_to_upload:
        result = upload_articles_to_db(sample_articles_to_upload, sample_db_config)
        print(f"Example upload result: {result}")
    else:
        print("No sample articles to upload after filtering.")
    print("------------------------------") 